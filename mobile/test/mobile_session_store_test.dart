import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:inventory_kona/core/api/mobile_session_store.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
  });

  test(
    'clear emits a session-cleared event and removes persisted tokens',
    () async {
      const storage = FlutterSecureStorage();
      final store = MobileSessionStore(storage);
      final cleared = expectLater(store.sessionCleared, emits(null));

      await store.save(
        accessToken: 'access-token',
        refreshToken: 'refresh-token',
        deviceId: 'device-id',
        keepSignedIn: true,
        email: 'staff@example.com',
      );
      expect(await store.hasSession, isTrue);

      await store.clear();
      await cleared;

      expect(await store.hasSession, isFalse);
      expect(await store.keepSignedIn, isFalse);
      expect(await storage.read(key: 'kona_access_token'), isNull);
      expect(await storage.read(key: 'kona_refresh_token'), isNull);
      await store.dispose();
    },
  );
}
