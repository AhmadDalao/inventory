class AppConfig {
  const AppConfig._();

  static const mockMode = bool.fromEnvironment('MOCK_MODE', defaultValue: true);
  static const apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://inventory.ahmaddalao.com/api/v1',
  );
  static const appVersion = String.fromEnvironment(
    'APP_VERSION',
    defaultValue: '1.2.1',
  );
}
