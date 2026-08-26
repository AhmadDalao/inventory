import 'package:flutter_test/flutter_test.dart';
import 'package:inventory_kona/core/config/app_config.dart';

void main() {
  test('release-safe defaults use the live API', () {
    expect(AppConfig.mockMode, isFalse);
    expect(AppConfig.apiBaseUrl, startsWith('https://'));
  });
}
