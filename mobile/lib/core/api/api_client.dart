import 'dart:async';
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

import '../config/app_config.dart';
import 'mobile_session_store.dart';

class ApiClient {
  ApiClient(this._sessionStore)
    : dio = Dio(
        BaseOptions(
          baseUrl: AppConfig.apiBaseUrl,
          connectTimeout: const Duration(seconds: 20),
          receiveTimeout: const Duration(seconds: 30),
          headers: {
            'Accept': 'application/json',
            'X-App-Version': AppConfig.appVersion,
          },
        ),
      ) {
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await _sessionStore.accessToken;
          if (token != null && token.isNotEmpty) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          handler.next(options);
        },
        onError: (error, handler) async {
          final request = error.requestOptions;
          final alreadyRetried = request.extra['token_refresh_retry'] == true;
          final isAuthRoute = request.path.startsWith('/auth/');
          if (error.response?.statusCode == 401 &&
              !alreadyRetried &&
              !isAuthRoute &&
              await _refreshSession()) {
            request.extra['token_refresh_retry'] = true;
            final token = await _sessionStore.accessToken;
            request.headers['Authorization'] = 'Bearer $token';
            try {
              handler.resolve(await dio.fetch<dynamic>(request));
              return;
            } on DioException catch (retryError) {
              handler.next(retryError);
              return;
            }
          }
          handler.next(error);
        },
      ),
    );
  }

  final Dio dio;
  final MobileSessionStore _sessionStore;
  final Uuid _uuid = const Uuid();
  Future<bool>? _refreshing;

  String operationId() => _uuid.v4();

  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
  }) =>
      _send(() => dio.get<Map<String, dynamic>>(path, queryParameters: query));

  Future<Map<String, dynamic>> post(String path, {Object? data}) =>
      _send(() => dio.post<Map<String, dynamic>>(path, data: data));

  Future<Map<String, dynamic>> postMultipart(
    String path, {
    required Map<String, dynamic> fields,
    required String filePath,
    String fileField = 'proof_image',
  }) async {
    final form = FormData.fromMap({
      'payload': _json(fields),
      fileField: await MultipartFile.fromFile(filePath),
    });
    return _send(() => dio.post<Map<String, dynamic>>(path, data: form));
  }

  Future<Map<String, dynamic>> postMultipartFiles(
    String path, {
    required Map<String, dynamic> fields,
    required Map<String, String> files,
  }) async {
    final formValues = <String, dynamic>{'payload': _json(fields)};
    for (final entry in files.entries) {
      formValues[entry.key] = await MultipartFile.fromFile(entry.value);
    }
    return _send(
      () => dio.post<Map<String, dynamic>>(
        path,
        data: FormData.fromMap(formValues),
      ),
    );
  }

  Future<Map<String, dynamic>> _send(
    Future<Response<Map<String, dynamic>>> Function() request,
  ) async {
    try {
      final response = await request();
      return _unwrap(response.data);
    } on DioException catch (error) {
      final body = error.response?.data;
      if (body is Map<String, dynamic>) return _unwrap(body);
      if (body is Map) {
        return _unwrap(Map<String, dynamic>.from(body));
      }
      rethrow;
    }
  }

  Future<bool> _refreshSession() {
    final active = _refreshing;
    if (active != null) return active;
    final future = _performRefresh();
    _refreshing = future;
    return future.whenComplete(() => _refreshing = null);
  }

  Future<bool> _performRefresh() async {
    final refreshToken = await _sessionStore.refreshToken;
    if (refreshToken == null || refreshToken.isEmpty) return false;
    final refreshDio = Dio(
      BaseOptions(
        baseUrl: AppConfig.apiBaseUrl,
        connectTimeout: const Duration(seconds: 20),
        receiveTimeout: const Duration(seconds: 30),
        headers: {
          'Accept': 'application/json',
          'X-App-Version': AppConfig.appVersion,
        },
      ),
    );
    try {
      final response = await refreshDio.post<Map<String, dynamic>>(
        '/auth/refresh',
        data: {'refresh_token': refreshToken},
      );
      final data = _unwrap(response.data);
      final access = data['access_token'] as String?;
      final refresh = data['refresh_token'] as String?;
      if (access == null || refresh == null) return false;
      await _sessionStore.saveTokens(
        accessToken: access,
        refreshToken: refresh,
      );
      return true;
    } on DioException catch (_) {
      await _sessionStore.clear();
      return false;
    } on ApiFailure catch (_) {
      await _sessionStore.clear();
      return false;
    }
  }

  String _json(Map<String, dynamic> value) =>
      const JsonEncoder().convert(value);

  Map<String, dynamic> _unwrap(Map<String, dynamic>? body) {
    if (body == null) {
      throw StateError('The server returned an empty response.');
    }
    final error = body['error'];
    if (error is Map<String, dynamic>) {
      throw ApiFailure(
        error['code'] as String? ?? 'request_failed',
        error['message'] as String? ?? 'The request failed.',
        retrySafe: error['retryable'] == true,
        fieldErrors: error['fields'] is Map
            ? Map<String, dynamic>.from(error['fields'] as Map)
            : const {},
      );
    }
    final data = body['data'];
    return data is Map<String, dynamic>
        ? data
        : <String, dynamic>{'items': data};
  }
}

String apiErrorMessage(
  Object error, {
  String fallback = 'The request could not be completed. Try again.',
}) {
  if (error is ApiFailure) {
    return switch (error.code) {
      'mobile_disabled' =>
        'The mobile app is not enabled yet. Ask the owner to enable Mobile Access on the website.',
      'mobile_access_denied' || 'mobile_access_revoked' =>
        'Mobile access is not enabled for this account. Ask the owner to update Mobile Access.',
      'upgrade_required' =>
        'This app version is no longer supported. Install the latest Inventory KONA APK.',
      'rate_limited' => 'Too many attempts. Wait a moment, then try again.',
      _ => error.message,
    };
  }

  if (error is DioException) {
    final responseMessage = _dioResponseMessage(error.response?.data);
    if (responseMessage != null) return responseMessage;

    return switch (error.type) {
      DioExceptionType.connectionTimeout ||
      DioExceptionType.sendTimeout ||
      DioExceptionType.receiveTimeout =>
        'The server took too long to respond. Check your connection and try again.',
      DioExceptionType.connectionError =>
        'The server could not be reached. Check your internet connection and try again.',
      DioExceptionType.badCertificate =>
        'The secure server connection could not be verified.',
      _ => fallback,
    };
  }

  return fallback;
}

String? _dioResponseMessage(Object? body) {
  if (body is! Map) return null;
  final error = body['error'];
  if (error is! Map) return null;
  final message = error['message'];
  return message is String && message.trim().isNotEmpty ? message.trim() : null;
}

class ApiFailure implements Exception {
  const ApiFailure(
    this.code,
    this.message, {
    this.retrySafe = false,
    this.fieldErrors = const {},
  });

  final String code;
  final String message;
  final bool retrySafe;
  final Map<String, dynamic> fieldErrors;

  @override
  String toString() => message;
}
