import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../api/api_client.dart';
import '../api/mobile_session_store.dart';
import '../config/app_config.dart';
import '../models/inventory_models.dart';
import 'api_inventory_repository.dart';
import 'inventory_repository.dart';
import 'mock_inventory_repository.dart';
import 'draft_store.dart';
import 'local/app_database.dart';

final sessionStoreProvider = Provider<MobileSessionStore>((ref) {
  return MobileSessionStore(const FlutterSecureStorage());
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

final bootstrapProvider = FutureProvider<MobileBootstrap>((ref) {
  return ref.watch(inventoryRepositoryProvider).bootstrap();
});

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
