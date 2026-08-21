import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:inventory_kona/core/api/api_client.dart';

void main() {
  group('API errors', () {
    test(
      'mobile-disabled response is actionable and hides transport details',
      () {
        final message = apiErrorMessage(
          const ApiFailure(
            'mobile_disabled',
            'Mobile access is currently disabled.',
          ),
        );

        expect(message, contains('enable Mobile Access'));
        expect(message, isNot(contains('DioException')));
        expect(message, isNot(contains('503')));
      },
    );

    test('connection errors use a short user-facing message', () {
      final message = apiErrorMessage(
        DioException(
          requestOptions: RequestOptions(path: '/auth/login'),
          type: DioExceptionType.connectionError,
        ),
      );

      expect(message, contains('internet connection'));
      expect(message, isNot(contains('DioException')));
    });

    test('known API validation messages remain visible', () {
      const expected = 'Email or password is incorrect.';
      expect(
        apiErrorMessage(const ApiFailure('invalid_credentials', expected)),
        expected,
      );
    });

    test('incomplete mobile setup explains the owner action', () {
      final message = apiErrorMessage(
        const ApiFailure(
          'mobile_setup_incomplete',
          'Mobile inventory setup is incomplete.',
        ),
      );

      expect(message, contains('assign your manager'));
      expect(message, contains('storages'));
      expect(message, contains('Mobile Access'));
    });
  });
}
