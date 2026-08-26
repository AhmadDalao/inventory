import 'dart:async';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class MobileSessionStore {
  MobileSessionStore(this._storage);

  final FlutterSecureStorage _storage;

  static const _accessKey = 'kona_access_token';
  static const _refreshKey = 'kona_refresh_token';
  static const _deviceKey = 'kona_device_id';
  static const _keepSignedInKey = 'kona_keep_signed_in';
  static const _biometricKey = 'kona_biometric_unlock';
  static const _emailKey = 'kona_last_email';

  String? _memoryAccessToken;
  String? _memoryRefreshToken;
  final StreamController<void> _sessionClearedController =
      StreamController<void>.broadcast();

  Stream<void> get sessionCleared => _sessionClearedController.stream;

  Future<String?> get accessToken async {
    if (_memoryAccessToken?.isNotEmpty ?? false) return _memoryAccessToken;
    if (!await keepSignedIn) return null;
    return _storage.read(key: _accessKey);
  }

  Future<String?> get refreshToken async {
    if (_memoryRefreshToken?.isNotEmpty ?? false) return _memoryRefreshToken;
    if (!await keepSignedIn) return null;
    return _storage.read(key: _refreshKey);
  }

  Future<String?> get deviceId => _storage.read(key: _deviceKey);
  Future<String?> get savedEmail => _storage.read(key: _emailKey);
  Future<bool> get keepSignedIn => _readBool(_keepSignedInKey);
  Future<bool> get biometricUnlock => _readBool(_biometricKey);

  Future<bool> get hasSession async {
    final access = await accessToken;
    final refresh = await refreshToken;
    return (access?.isNotEmpty ?? false) || (refresh?.isNotEmpty ?? false);
  }

  Future<void> save({
    required String accessToken,
    required String refreshToken,
    required String deviceId,
    required bool keepSignedIn,
    required String email,
  }) async {
    _memoryAccessToken = accessToken;
    _memoryRefreshToken = refreshToken;
    await Future.wait([
      if (keepSignedIn) _storage.write(key: _accessKey, value: accessToken),
      if (keepSignedIn) _storage.write(key: _refreshKey, value: refreshToken),
      if (!keepSignedIn) _storage.delete(key: _accessKey),
      if (!keepSignedIn) _storage.delete(key: _refreshKey),
      _storage.write(key: _deviceKey, value: deviceId),
      _storage.write(key: _keepSignedInKey, value: keepSignedIn.toString()),
      _storage.write(key: _emailKey, value: email),
      if (!keepSignedIn)
        _storage.write(key: _biometricKey, value: false.toString()),
    ]);
  }

  Future<void> saveTokens({
    required String accessToken,
    required String refreshToken,
  }) async {
    _memoryAccessToken = accessToken;
    _memoryRefreshToken = refreshToken;
    if (await keepSignedIn) {
      await Future.wait([
        _storage.write(key: _accessKey, value: accessToken),
        _storage.write(key: _refreshKey, value: refreshToken),
      ]);
    }
  }

  Future<void> setKeepSignedIn(bool value) async {
    await _storage.write(key: _keepSignedInKey, value: value.toString());
    if (!value) {
      await Future.wait([
        _storage.delete(key: _accessKey),
        _storage.delete(key: _refreshKey),
        setBiometricUnlock(false),
      ]);
    } else if ((_memoryAccessToken?.isNotEmpty ?? false) &&
        (_memoryRefreshToken?.isNotEmpty ?? false)) {
      await Future.wait([
        _storage.write(key: _accessKey, value: _memoryAccessToken),
        _storage.write(key: _refreshKey, value: _memoryRefreshToken),
      ]);
    }
  }

  Future<void> setBiometricUnlock(bool value) =>
      _storage.write(key: _biometricKey, value: value.toString());

  Future<void> clear() async {
    _memoryAccessToken = null;
    _memoryRefreshToken = null;
    await Future.wait([
      _storage.delete(key: _accessKey),
      _storage.delete(key: _refreshKey),
      _storage.write(key: _keepSignedInKey, value: false.toString()),
      _storage.write(key: _biometricKey, value: false.toString()),
    ]);
    if (!_sessionClearedController.isClosed) {
      _sessionClearedController.add(null);
    }
  }

  Future<void> dispose() => _sessionClearedController.close();

  Future<bool> _readBool(String key) async =>
      (await _storage.read(key: key)) == true.toString();
}
