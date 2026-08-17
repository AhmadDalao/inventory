import 'package:flutter_test/flutter_test.dart';
import 'package:inventory_kona/core/models/inventory_models.dart';

void main() {
  const storage = StorageLocation(
    id: 10,
    name: 'KONA',
    type: 'storage',
    isDefault: true,
  );

  MobileBootstrap access({
    Set<String> permissions = const {},
    Set<String> capabilities = const {},
    List<StorageLocation> storages = const [storage],
    Map<String, dynamic> settings = const {'manual_restock_enabled': true},
  }) => MobileBootstrap(
    userName: 'Employee',
    storages: storages,
    items: const [],
    tasks: const [],
    permissions: permissions,
    capabilities: capabilities,
    settings: settings,
  );

  group('mobile access policy', () {
    test('a mobile capability cannot bypass the website permission matrix', () {
      final bootstrap = access(capabilities: const {'usage', 'handover'});

      expect(bootstrap.canViewItems, isFalse);
      expect(bootstrap.canUseStock, isFalse);
      expect(bootstrap.canCreateAnyHandover, isFalse);
      expect(bootstrap.hasScanOutAction, isFalse);
    });

    test('assigned storage is required for stock actions', () {
      final bootstrap = access(
        permissions: const {'items.view', 'movements.usage'},
        capabilities: const {'usage'},
        storages: const [],
      );

      expect(bootstrap.canViewItems, isFalse);
      expect(bootstrap.canUseStock, isFalse);
    });

    test('usage requires permission, capability, and assigned storage', () {
      final allowed = access(
        permissions: const {'items.view', 'movements.usage'},
        capabilities: const {'usage'},
      );
      final deniedByCapability = access(
        permissions: const {'items.view', 'movements.usage'},
      );

      expect(allowed.canUseStock, isTrue);
      expect(deniedByCapability.canUseStock, isFalse);
    });

    test('direct restock also obeys its global setting', () {
      final enabled = access(
        permissions: const {'items.view', 'movements.restock'},
        capabilities: const {'restock'},
      );
      final disabled = access(
        permissions: const {'items.view', 'movements.restock'},
        capabilities: const {'restock'},
        settings: const {'manual_restock_enabled': false},
      );

      expect(enabled.canRestock, isTrue);
      expect(disabled.canRestock, isFalse);
    });

    test('handover purposes use separate permission intersections', () {
      final requestOnly = access(
        permissions: const {'items.view', 'handovers.request'},
        capabilities: const {'handover', 'transfer', 'custody'},
      );

      expect(requestOnly.canCreateHandoverPurpose('temporary_use'), isTrue);
      expect(requestOnly.canCreateHandoverPurpose('storage_transfer'), isFalse);
      expect(requestOnly.canCreateHandoverPurpose('staff_custody'), isFalse);

      final issuer = access(
        permissions: const {'items.view', 'handovers.create'},
        capabilities: const {'handover', 'transfer', 'custody'},
      );

      expect(issuer.canCreateHandoverPurpose('temporary_use'), isTrue);
      expect(issuer.canCreateHandoverPurpose('storage_transfer'), isTrue);
      expect(issuer.canCreateHandoverPurpose('staff_custody'), isTrue);
    });

    test('handover screens trust only server-provided record actions', () {
      const task = MobileTask(
        id: 77,
        reference: 'HDO-77',
        title: 'Temporary handover',
        status: 'delivered',
        purpose: 'temporary_use',
        itemCount: 1,
        quantity: 10,
        allowedActions: {'report_closeout'},
        requiresAction: true,
      );

      expect(task.can('report_closeout'), isTrue);
      expect(task.can('approve_closeout'), isFalse);
      expect(task.can('cancel'), isFalse);
    });

    test(
      'storage payload preserves the server access role and storage type',
      () {
        final ownedStorage = StorageLocation.fromJson(const {
          'id': 12,
          'name': 'KONA Office',
          'storage_type': 'warehouse',
          'access_role': 'owner',
          'is_default': 1,
        });

        expect(ownedStorage.type, 'warehouse');
        expect(ownedStorage.isOwner, isTrue);
        expect(ownedStorage.isDefault, isTrue);
      },
    );

    test('manager identity survives realtime inventory merges', () {
      const manager = MobileManager(
        id: 2,
        name: 'Operations Manager',
        role: 'admin',
        position: 'Operations Manager',
      );
      final bootstrap = MobileBootstrap(
        userName: 'Employee',
        storages: const [storage],
        items: const [],
        tasks: const [],
        permissions: const {'items.view'},
        capabilities: const {},
        manager: manager,
      );

      expect(bootstrap.copyWith(items: const []).manager?.id, 2);
    });
  });
}
