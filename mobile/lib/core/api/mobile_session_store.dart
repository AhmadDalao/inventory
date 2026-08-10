import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class MobileSessionStore {
  MobileSessionStore(this._storage);

  final FlutterSecureStorage _storage;

  static const _accessKey = 'kona_access_token';
  static const _refreshKey = 'kona_refresh_token';
  static const _deviceKey = 'kona_device_id';

  Future<String?> get accessToken => _storage.read(key: _accessKey);
  Future<String?> get refreshToken => _storage.read(key: _refreshKey);
  Future<String?> get deviceId => _storage.read(key: _deviceKey);

  Future<void> save({
    required String accessToken,
    required String refreshToken,
    required String deviceId,
  }) async {
    await Future.wait([
      _storage.write(key: _accessKey, value: accessToken),
      _storage.write(key: _refreshKey, value: refreshToken),
      _storage.write(key: _deviceKey, value: deviceId),
    ]);
  }

  Future<void> saveTokens({
    required String accessToken,
    required String refreshToken,
  }) async {
    await Future.wait([
      _storage.write(key: _accessKey, value: accessToken),
      _storage.write(key: _refreshKey, value: refreshToken),
    ]);
  }

  Future<void> clear() => _storage.deleteAll();
}
