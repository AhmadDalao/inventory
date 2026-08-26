class AppConfig {
  const AppConfig._();

  // Production is the safe default. Demo data must be enabled explicitly.
  static const mockMode = bool.fromEnvironment(
    'MOCK_MODE',
    defaultValue: false,
  );
  static const apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://inventory.ahmaddalao.com/api/v1',
  );
  static const appVersion = String.fromEnvironment(
    'APP_VERSION',
    defaultValue: '1.3.3',
  );
}
