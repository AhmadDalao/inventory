import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../api/api_client.dart';
import '../api/mobile_session_store.dart';
import '../config/app_config.dart';
import '../models/inventory_models.dart';
import '../security/biometric_authenticator.dart';
import '../sync/mobile_realtime_sync.dart';
import 'api_inventory_repository.dart';
import 'inventory_repository.dart';
import 'mock_inventory_repository.dart';
import 'draft_store.dart';
import 'local/app_database.dart';

final sessionStoreProvider = Provider<MobileSessionStore>((ref) {
  return MobileSessionStore(const FlutterSecureStorage());
});

final biometricAuthenticatorProvider = Provider<BiometricAuthenticator>((ref) {
  return DeviceBiometricAuthenticator();
});

final apiClientProvider = Provider<ApiClient>((ref) {
  return ApiClient(ref.watch(sessionStoreProvider));
});

final inventoryRepositoryProvider = Provider<InventoryRepository>((ref) {
  if (AppConfig.mockMode) return MockInventoryRepository();
  return ApiInventoryRepository(
    ref.watch(apiClientProvider),
    ref.watch(sessionStoreProvider),
  );
});

final bootstrapProvider =
    AsyncNotifierProvider<BootstrapController, MobileBootstrap>(
      BootstrapController.new,
    );

class BootstrapController extends AsyncNotifier<MobileBootstrap> {
  @override
  Future<MobileBootstrap> build() {
    return ref.watch(inventoryRepositoryProvider).bootstrap();
  }

  Future<void> reload() async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(
      () => ref.read(inventoryRepositoryProvider).bootstrap(),
    );
  }

  void applyDeltas(List<MobileSyncDelta> deltas) {
    var current = state.valueOrNull;
    if (current == null) return;
    for (final delta in deltas) {
      current = current!.mergeSyncDelta(delta);
    }
    state = AsyncData(current!);
  }
}

final handoversProvider = FutureProvider<List<MobileTask>>((ref) {
  return ref.watch(inventoryRepositoryProvider).handovers();
});

final mobileOperationsProvider = FutureProvider<List<MobileOperation>>((ref) {
  return ref.watch(inventoryRepositoryProvider).operations();
});

final handoverDetailProvider = FutureProvider.family<HandoverDetail, int>((
  ref,
  id,
) {
  return ref.watch(inventoryRepositoryProvider).handover(id);
});

final appDatabaseProvider = Provider<AppDatabase>((ref) {
  final database = AppDatabase();
  ref.onDispose(database.close);
  return database;
});

final draftStoreProvider = Provider<DraftStore>(
  (ref) => DraftStore(ref.watch(appDatabaseProvider)),
);

final pendingDraftsProvider = StreamProvider<List<PendingDraft>>((ref) {
  return ref.watch(draftStoreProvider).watchAll();
});

final mobileSessionRevokedProvider = StateProvider<bool>((ref) => false);

final mobileRealtimeSyncProvider = Provider<MobileRealtimeSync>((ref) {
  final sync = MobileRealtimeSync(
    repository: ref.watch(inventoryRepositoryProvider),
    session: ref.watch(sessionStoreProvider),
    onChanged: (deltas, requiresBootstrap) async {
      if (requiresBootstrap) {
        await ref.read(bootstrapProvider.notifier).reload();
      } else {
        ref.read(bootstrapProvider.notifier).applyDeltas(deltas);
      }
      ref.invalidate(handoversProvider);
      ref.invalidate(mobileOperationsProvider);
    },
    onRevoked: () async {
      ref.read(mobileSessionRevokedProvider.notifier).state = true;
      ref.invalidate(bootstrapProvider);
    },
  );
  sync.start();
  ref.onDispose(sync.dispose);
  return sync;
});
