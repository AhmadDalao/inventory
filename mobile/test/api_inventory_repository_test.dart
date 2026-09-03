import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:inventory_kona/core/api/api_client.dart';
import 'package:inventory_kona/core/api/mobile_session_store.dart';
import 'package:inventory_kona/core/data/api_inventory_repository.dart';
import 'package:inventory_kona/core/models/inventory_models.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test(
    'usage sends the wristband batch contract and parses its receipt',
    () async {
      FlutterSecureStorage.setMockInitialValues({});
      final session = MobileSessionStore(const FlutterSecureStorage());
      addTearDown(session.dispose);
      final api = ApiClient(session);
      late RequestOptions request;
      api.dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (options, handler) {
            request = options;
            handler.resolve(
              Response<Map<String, dynamic>>(
                requestOptions: options,
                statusCode: 201,
                data: const {
                  'data': {
                    'operation_id': 42,
                    'reference': 'MOB-BATCH-42',
                    'status': 'completed',
                    'sync_cursor': 901,
                    'balance_updates': [
                      {
                        'item_id': 15,
                        'storage_id': 10,
                        'storage_balance': 98,
                        'item_name': 'Blue Wristband',
                        'sku': 'WB-BLUE',
                        'unit': 'pcs',
                        'active': true,
                      },
                    ],
                    'lines': [
                      {
                        'movement_id': 77,
                        'item_id': 15,
                        'storage_id': 10,
                        'storage_balance': 98,
                      },
                    ],
                  },
                  'meta': {'atomic': true},
                  'error': null,
                },
              ),
            );
          },
        ),
      );
      final repository = ApiInventoryRepository(api, session);
      const item = InventoryItem(
        id: 15,
        name: 'Blue Wristband',
        sku: 'WB-BLUE',
        unit: 'pcs',
        quantity: 100,
        storageId: 10,
        storageName: 'KONA',
      );

      final receipt = await repository.submitUsage(
        storageId: 10,
        lines: const [
          CartLine(
            item: item,
            quantity: 2,
            expectedBalance: 100,
            reasonCode: 'no-show',
          ),
        ],
        defaultReason: 'online',
        notes: 'Guest usage',
        clientOperationId: 'usage-retry-safe-42',
      );

      expect(request.path, '/movements/batch');
      expect(request.headers['X-App-Version'], isNotEmpty);
      final payload = Map<String, dynamic>.from(request.data as Map);
      expect(payload['client_operation_id'], 'usage-retry-safe-42');
      final line = Map<String, dynamic>.from(
        (payload['lines'] as List).single as Map,
      );
      expect(line, containsPair('type', 'usage'));
      expect(line, containsPair('item_id', 15));
      expect(line, containsPair('storage_id', 10));
      expect(line, containsPair('input_quantity', 2));
      expect(line, containsPair('package_preset_id', null));
      expect(line, containsPair('expected_balance', 100));
      expect(line, containsPair('reason', 'no_show'));
      expect(line, containsPair('custom_reason', null));
      expect(receipt.reference, 'MOB-BATCH-42');
      expect(receipt.syncCursor, 901);
      expect(receipt.balanceUpdates.single.storageBalance, 98);
    },
  );
}
