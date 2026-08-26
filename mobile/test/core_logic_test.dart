import 'package:flutter_test/flutter_test.dart';
import 'package:inventory_kona/core/data/mock_inventory_repository.dart';
import 'package:inventory_kona/core/api/api_client.dart';
import 'package:inventory_kona/core/logic/handover_reconciliation.dart';
import 'package:inventory_kona/core/logic/scan_debouncer.dart';
import 'package:inventory_kona/core/models/inventory_models.dart';
import 'package:inventory_kona/features/movements/measured_cart_support.dart';
import 'package:inventory_kona/features/sync/draft_replay.dart';

void main() {
  group('storage usage profiles', () {
    final bootstrap = MobileBootstrap(
      userName: 'Employee',
      storages: const [
        StorageLocation(id: 10, name: 'Wristbands', usageProfile: 'wristband'),
        StorageLocation(id: 11, name: 'Cleaning', usageProfile: 'general'),
      ],
      items: const [],
      tasks: const [],
      capabilities: const {},
      settings: const {
        'usage_reason_catalogs': {
          'wristband': [
            {
              'code': 'online',
              'label': 'Online',
              'active': true,
              'sort_order': 1,
            },
          ],
          'general': [
            {
              'code': 'cleaning',
              'label': 'Cleaning',
              'active': true,
              'sort_order': 1,
            },
          ],
        },
      },
    );

    test('uses wristband reasons for wristband storage', () {
      expect(
        bootstrap.usageReasonsForStorage(10).map((reason) => reason.code),
        contains('online'),
      );
      expect(
        bootstrap.usageReasonsForStorage(10).map((reason) => reason.code),
        isNot(contains('cleaning')),
      );
    });

    test('uses operational reasons for general storage', () {
      expect(
        bootstrap.usageReasonsForStorage(11).map((reason) => reason.code),
        contains('cleaning'),
      );
      expect(
        bootstrap.usageReasonsForStorage(11).map((reason) => reason.code),
        isNot(contains('online')),
      );
    });

    test('old storage payloads retain wristband compatibility', () {
      final storage = StorageLocation.fromJson(const {
        'id': 12,
        'name': 'Legacy',
      });

      expect(storage.usageProfile, 'wristband');
    });
  });

  group('offline draft replay', () {
    final bootstrap = MobileBootstrap(
      userName: 'Employee',
      storages: const [
        StorageLocation(id: 10, name: 'KONA'),
        StorageLocation(id: 11, name: 'Office'),
      ],
      items: const [
        InventoryItem(
          id: 15,
          name: 'Soap',
          sku: 'SOAP',
          unit: 'mL',
          quantity: 5000,
          storageId: 10,
          storageName: 'KONA',
          packagePresets: [
            ItemPackagePreset(id: 7, label: '1 L bottle', piecesPerUnit: 1000),
          ],
        ),
        InventoryItem(
          id: 15,
          name: 'Soap',
          sku: 'SOAP',
          unit: 'mL',
          quantity: 2000,
          storageId: 11,
          storageName: 'Office',
        ),
      ],
      tasks: const [],
      capabilities: const {},
    );

    test('binds a draft item to its source storage balance', () {
      final lines = resolveDraftCartLines(
        {
          'lines': [
            {
              'item_id': 15,
              'input_quantity': 2,
              'package_preset_id': 7,
              'package_multiplier': 99,
              'expected_balance': 4800,
            },
          ],
        },
        bootstrap,
        sourceStorageId: 10,
      );

      expect(lines.single.item.storageId, 10);
      expect(lines.single.item.quantity, 5000);
      expect(lines.single.packageMultiplier, 1000);
      expect(lines.single.baseQuantity, 2000);
      expect(lines.single.expectedBalance, 4800);
    });

    test('blocks replay when storage access was revoked', () {
      expect(
        () => resolveDraftCartLines(
          {
            'lines': [
              {'item_id': 15, 'quantity': 1},
            ],
          },
          bootstrap,
          sourceStorageId: 99,
        ),
        throwsA(isA<DraftReplayException>()),
      );
    });

    test('blocks replay when a package preset was removed', () {
      expect(
        () => resolveDraftCartLines(
          {
            'lines': [
              {'item_id': 15, 'quantity': 1, 'package_preset_id': 999},
            ],
          },
          bootstrap,
          sourceStorageId: 10,
        ),
        throwsA(isA<DraftReplayException>()),
      );
    });
  });

  test(
    'mock count handover uses whole quantities that match its total',
    () async {
      final repository = MockInventoryRepository();
      final handover = await repository.handover(101);

      expect(
        handover.lines.fold<double>(
          0,
          (sum, line) => sum + line.quantityIssued,
        ),
        handover.task.quantity,
      );
      expect(
        handover.lines.every(
          (line) => line.quantityIssued == line.quantityIssued.roundToDouble(),
        ),
        isTrue,
      );
    },
  );

  group('mock handover lifecycle', () {
    test('exact temporary-use receipt advances to usage reporting', () async {
      final repository = MockInventoryRepository();
      final before = await repository.handover(101);
      final quantities = {
        for (final line in before.lines) line.id: line.quantityIssued,
      };

      final receipt = await repository.confirmReceipt(101, quantities);
      final after = await repository.handover(101);

      expect(receipt.status, 'delivered');
      expect(after.task.status, 'delivered');
      expect(after.task.can('report_closeout'), isTrue);
      expect(
        after.lines.map((line) => line.quantityReceived),
        before.lines.map((line) => line.quantityIssued),
      );
    });

    test('receipt difference waits for issuer review', () async {
      final repository = MockInventoryRepository();
      final before = await repository.handover(101);
      final quantities = {
        for (final line in before.lines) line.id: line.quantityIssued,
      };
      quantities[before.lines.first.id] = before.lines.first.quantityIssued - 1;

      final receipt = await repository.confirmReceipt(101, quantities);
      final after = await repository.handover(101);

      expect(receipt.status, 'receipt_review');
      expect(after.task.status, 'receipt_review');
      expect(after.task.can('review_receipt'), isTrue);
      expect(
        after.lines.first.quantityReceived,
        before.lines.first.quantityIssued - 1,
      );
    });

    test('exact storage transfer closes after destination receipt', () async {
      final repository = MockInventoryRepository();
      final before = await repository.handover(102);
      final quantities = {
        for (final line in before.lines) line.id: line.quantityIssued,
      };

      final receipt = await repository.confirmReceipt(102, quantities);
      final after = await repository.handover(102);

      expect(receipt.status, 'closed');
      expect(after.task.status, 'closed');
      expect(after.task.allowedActions, isEmpty);
    });

    test('temporary-use closeout advances through owner approval', () async {
      final repository = MockInventoryRepository();
      final before = await repository.handover(101);
      await repository.confirmReceipt(101, {
        for (final line in before.lines) line.id: line.quantityIssued,
      });
      final delivered = await repository.handover(101);
      final returned = {
        for (final line in delivered.lines) line.id: line.quantityReceived - 1,
      };
      const reconciliation = {
        'pcs': {'online': 3.0},
      };

      final submitted = await repository.submitCloseout(
        handoverId: 101,
        returnedQuantities: returned,
        reconciliations: reconciliation,
      );
      final pending = await repository.handover(101);

      expect(submitted.status, 'pending_approval');
      expect(pending.task.can('approve_closeout'), isTrue);
      expect(
        pending.lines.fold<double>(0, (sum, line) => sum + line.quantityUsed),
        3,
      );

      final approved = await repository.approveCloseout(
        handoverId: 101,
        returnedQuantities: returned,
        reconciliations: reconciliation,
      );
      final closed = await repository.handover(101);

      expect(approved.status, 'closed');
      expect(closed.task.status, 'closed');
      expect(closed.task.allowedActions, isEmpty);
    });

    test('custody return rejects an empty submission', () async {
      final repository = MockInventoryRepository();

      await expectLater(
        repository.submitCustodyReturn(handoverId: 103, lines: const []),
        throwsArgumentError,
      );
    });

    test('custody damage requires proof and valid totals', () async {
      final repository = MockInventoryRepository();
      final detail = await repository.handover(103);
      final line = detail.lines.first;
      final damaged = CustodyReturnLine(handoverLineId: line.id, damaged: 1);

      await expectLater(
        repository.submitCustodyReturn(handoverId: 103, lines: [damaged]),
        throwsArgumentError,
      );

      final receipt = await repository.submitCustodyReturn(
        handoverId: 103,
        lines: [damaged],
        damageProofPaths: {line.id: '/tmp/damage.jpg'},
      );

      expect(receipt.status, 'pending_approval');
    });

    test('custody lost quantity requires an explanation', () async {
      final repository = MockInventoryRepository();
      final detail = await repository.handover(103);
      final line = detail.lines.first;

      await expectLater(
        repository.submitCustodyReturn(
          handoverId: 103,
          lines: [CustodyReturnLine(handoverLineId: line.id, lost: 1)],
        ),
        throwsArgumentError,
      );
    });
  });

  group('handover reconciliation', () {
    test('returned-first physical usage is calculated safely', () {
      expect(
        HandoverReconciliationMath.physicalUsed(received: 326, returned: 66),
        260,
      );
      expect(
        HandoverReconciliationMath.physicalUsed(received: 10, returned: 12),
        0,
      );
    });

    test('operational summary follows online minus no-show rule', () {
      const reasons = <String, double>{
        'online': 244,
        'noshow': 5,
        'walkin': 11,
        'complimentary': 10,
      };

      expect(HandoverReconciliationMath.operationalUsed(reasons), 260);
      expect(
        HandoverReconciliationMath.difference(
          physicalUsed: 260,
          reasons: reasons,
        ),
        0,
      );
      expect(HandoverReconciliationMath.noShowIsValid(reasons), isTrue);
      expect(
        HandoverReconciliationMath.noShowIsValid(const {
          'online': 4,
          'noshow': 5,
        }),
        isFalse,
      );
    });
  });

  test('package conversion produces review-cart piece quantity', () {
    const item = InventoryItem(
      id: 1,
      name: 'Wristband',
      sku: 'WB-1',
      unit: 'pcs',
      quantity: 500,
      storageId: 1,
      storageName: 'KONA',
    );
    const line = CartLine(
      item: item,
      quantity: 3,
      packageLabel: 'Box',
      packageMultiplier: 50,
    );

    expect(line.pieceQuantity, 150);
  });

  test(
    'bootstrap reason catalog includes School and normalizes legacy codes',
    () {
      final reasons = <UsageReason>[
        UsageReason.fromJson(const {
          'code': 'school',
          'label': 'School',
          'active': true,
          'sort_order': 6,
        }),
        UsageReason.fromJson(const {
          'code': 'noshow',
          'label': 'No Show',
          'active': true,
          'sort_order': 8,
        }),
      ];

      expect(reasons.first.code, 'school');
      expect(reasons.last.code, 'no_show');
      expect(
        UsageReason.defaults.any((reason) => reason.code == 'school'),
        isTrue,
      );
    },
  );

  test('server package presets are parsed and used by cart lines', () {
    final item = InventoryItem.fromJson(const {
      'id': 15,
      'name': 'Wristband',
      'sku': 'WB-BLUE',
      'unit': 'pcs',
      'quantity': 500,
      'storage_id': 10,
      'storage_name': 'KONA',
      'package_presets': [
        {'id': 7, 'label': 'Bag', 'pieces_per_unit': 25, 'is_default': true},
      ],
    });
    final preset = item.packagePresets.single;
    final line = CartLine(
      item: item,
      quantity: 4,
      packageLabel: preset.label,
      packageMultiplier: preset.piecesPerUnit,
    );

    expect(preset.label, 'Bag');
    expect(line.pieceQuantity, 100);
  });

  test('measured package conversions preserve decimal base quantities', () {
    const soap = InventoryItem(
      id: 20,
      name: 'Floor soap',
      sku: 'SOAP-1',
      unit: 'ml',
      quantity: 10000,
      storageId: 10,
      storageName: 'KONA',
      measurementDimension: 'volume',
    );
    const rolls = InventoryItem(
      id: 21,
      name: 'Toilet paper',
      sku: 'ROLL-1',
      unit: 'roll',
      quantity: 200,
      storageId: 10,
      storageName: 'KONA',
    );
    const powder = InventoryItem(
      id: 22,
      name: 'Cleaning powder',
      sku: 'POWDER-1',
      unit: 'g',
      quantity: 20000,
      storageId: 10,
      storageName: 'KONA',
      measurementDimension: 'mass',
    );

    expect(
      const CartLine(
        item: soap,
        quantity: 2,
        packageLabel: '1 L bottle',
        packageMultiplier: 1000,
      ).baseQuantity,
      2000,
    );
    expect(
      const CartLine(
        item: soap,
        quantity: 3,
        packageLabel: '250 mL bottle',
        packageMultiplier: 250,
      ).baseQuantity,
      750,
    );
    expect(
      const CartLine(
        item: rolls,
        quantity: 2,
        packageLabel: '24-roll box',
        packageMultiplier: 24,
      ).baseQuantity,
      48,
    );
    expect(
      const CartLine(
        item: powder,
        quantity: 1.5,
        packageLabel: '5 kg bag',
        packageMultiplier: 5000,
      ).baseQuantity,
      7500,
    );
  });

  test('package barcode scans increment only the matching item and preset', () {
    final scanned = InventoryItem.fromJson(const {
      'id': 30,
      'name': 'Floor soap',
      'sku': 'SOAP-1',
      'unit': 'ml',
      'quantity': 12000,
      'storage_id': 10,
      'storage_name': 'KONA',
      'measurement_dimension': 'volume',
      'matched_package_preset_id': 8,
      'package_presets': [
        {
          'id': 8,
          'label': '1 L bottle',
          'pieces_per_unit': 1000,
          'scan_code': 'SOAP-1L',
          'is_active': true,
        },
      ],
    });
    final lines = <CartLine>[];

    addOrIncrementMeasuredLine(lines, scanned);
    addOrIncrementMeasuredLine(lines, scanned);

    expect(lines, hasLength(1));
    expect(lines.single.packagePresetId, 8);
    expect(lines.single.quantity, 2);
    expect(lines.single.baseQuantity, 2000);
  });

  test('item proof requirements are parsed for usage and refill', () {
    final item = InventoryItem.fromJson(const {
      'id': 40,
      'name': 'Cleaning chemical',
      'sku': 'CHEM-1',
      'unit': 'ml',
      'quantity': 5000,
      'storage_id': 10,
      'storage_name': 'KONA',
      'requires_usage_proof': true,
      'requires_refill_proof': 1,
    });

    expect(item.requiresUsageProof, isTrue);
    expect(item.requiresRefillProof, isTrue);
  });

  test('Other is the only default reason requiring a description', () {
    final other = UsageReason.defaults.singleWhere(
      (reason) => reason.code == 'other',
    );
    final school = UsageReason.defaults.singleWhere(
      (reason) => reason.code == 'school',
    );

    expect(other.requiresCustomText, isTrue);
    expect(school.requiresCustomText, isFalse);
  });

  test('scanner suppresses rapid duplicate reads but accepts later scans', () {
    final scanner = ScanDebouncer(window: const Duration(seconds: 2));
    final start = DateTime.utc(2026, 8, 10, 12);

    expect(scanner.accept('WB-BLUE', at: start), isTrue);
    expect(
      scanner.accept(
        'WB-BLUE',
        at: start.add(const Duration(milliseconds: 800)),
      ),
      isFalse,
    );
    expect(
      scanner.accept('WB-RED', at: start.add(const Duration(seconds: 1))),
      isTrue,
    );
    expect(
      scanner.accept('WB-BLUE', at: start.add(const Duration(seconds: 3))),
      isTrue,
    );
  });

  test('realtime delta replaces only changed authorized balances', () {
    const storage = StorageLocation(id: 10, name: 'KONA', isDefault: true);
    const original = MobileBootstrap(
      userName: 'Alaa',
      storages: [storage],
      items: [
        InventoryItem(
          id: 15,
          name: 'Blue',
          sku: 'WB-BLUE',
          unit: 'pcs',
          quantity: 100,
          storageId: 10,
          storageName: 'KONA',
        ),
      ],
      tasks: [],
      capabilities: {'usage'},
      permissions: {
        'mobile.access',
        'storages.view',
        'items.view',
        'movements.usage',
      },
    );
    const delta = MobileSyncDelta(
      nextCursor: 91,
      latestCursor: 91,
      hasMore: false,
      fullResyncRequired: false,
      items: [
        InventoryItem(
          id: 15,
          name: 'Blue',
          sku: 'WB-BLUE',
          unit: 'pcs',
          quantity: 94,
          storageId: 10,
          storageName: 'KONA',
        ),
      ],
      deletedItemIds: {},
      tasks: [],
      permissions: {
        'mobile.access',
        'storages.view',
        'items.view',
        'movements.usage',
      },
      capabilities: {'usage'},
      storageIds: {10},
    );

    final updated = original.mergeSyncDelta(delta);

    expect(updated.items.single.quantity, 94);
    expect(updated.defaultStorage?.id, 10);
    expect(updated.canUseStock, isTrue);
  });

  test('an empty realtime storage scope removes cached inventory access', () {
    const original = MobileBootstrap(
      userName: 'Alaa',
      storages: [StorageLocation(id: 10, name: 'KONA')],
      items: [
        InventoryItem(
          id: 15,
          name: 'Blue',
          sku: 'WB-BLUE',
          unit: 'pcs',
          quantity: 100,
          storageId: 10,
          storageName: 'KONA',
        ),
      ],
      tasks: [],
      capabilities: {'usage'},
      permissions: {
        'mobile.access',
        'storages.view',
        'items.view',
        'movements.usage',
      },
    );
    const revoked = MobileSyncDelta(
      nextCursor: 92,
      latestCursor: 92,
      hasMore: false,
      fullResyncRequired: false,
      items: [],
      deletedItemIds: {},
      tasks: [],
      permissions: {},
      capabilities: {},
      storageIds: {},
    );

    final updated = original.mergeSyncDelta(revoked);

    expect(updated.storages, isEmpty);
    expect(updated.items, isEmpty);
    expect(updated.canViewItems, isFalse);
  });

  test('mutation balance updates replace only matching cached balances', () {
    const original = MobileBootstrap(
      userName: 'Alaa',
      storages: [StorageLocation(id: 10, name: 'KONA', isDefault: true)],
      items: [
        InventoryItem(
          id: 15,
          name: 'Blue',
          sku: 'WB-BLUE',
          unit: 'pcs',
          quantity: 100,
          storageId: 10,
          storageName: 'KONA',
        ),
        InventoryItem(
          id: 16,
          name: 'Red',
          sku: 'WB-RED',
          unit: 'pcs',
          quantity: 80,
          storageId: 10,
          storageName: 'KONA',
        ),
      ],
      tasks: [],
      capabilities: {'usage'},
      permissions: {'mobile.access', 'items.view', 'movements.usage'},
    );
    const update = AuthoritativeBalanceUpdate(
      itemId: 15,
      storageId: 10,
      storageBalance: 94,
      itemName: 'Blue',
      sku: 'WB-BLUE',
      unit: 'pcs',
      active: true,
    );

    expect(original.canApplyBalanceUpdates(const [update]), isTrue);

    final updated = original.applyBalanceUpdates(const [update]);

    expect(updated.items.singleWhere((item) => item.id == 15).quantity, 94);
    expect(updated.items.singleWhere((item) => item.id == 16).quantity, 80);
  });

  test('mutation balance updates require a reload for unknown active rows', () {
    const original = MobileBootstrap(
      userName: 'Alaa',
      storages: [StorageLocation(id: 10, name: 'KONA', isDefault: true)],
      items: [],
      tasks: [],
      capabilities: {'restock'},
      permissions: {'mobile.access', 'items.view', 'movements.restock'},
    );
    final update = AuthoritativeBalanceUpdate.fromJson(const {
      'item_id': 15,
      'storage_id': 10,
      'storage_balance': 12.5,
      'item_name': 'Soap',
      'sku': 'SOAP-1L',
      'unit': 'ml',
      'active': true,
    });

    expect(update.storageBalance, 12.5);
    expect(original.canApplyBalanceUpdates([update]), isFalse);
  });

  test('inactive mutation balance updates remove cached inventory rows', () {
    const original = MobileBootstrap(
      userName: 'Alaa',
      storages: [StorageLocation(id: 10, name: 'KONA', isDefault: true)],
      items: [
        InventoryItem(
          id: 15,
          name: 'Blue',
          sku: 'WB-BLUE',
          unit: 'pcs',
          quantity: 100,
          storageId: 10,
          storageName: 'KONA',
        ),
      ],
      tasks: [],
      capabilities: {'usage'},
      permissions: {'mobile.access', 'items.view', 'movements.usage'},
    );
    const update = AuthoritativeBalanceUpdate(
      itemId: 15,
      storageId: 10,
      storageBalance: 0,
      itemName: 'Blue',
      sku: 'WB-BLUE',
      unit: 'pcs',
      active: false,
    );

    final updated = original.applyBalanceUpdates(const [update]);

    expect(updated.items, isEmpty);
  });

  test('balance conflicts expose authoritative server details safely', () {
    const failure = ApiFailure(
      'balance_changed',
      'The storage quantity changed.',
      retrySafe: true,
      details: {
        'item_id': 15,
        'storage_id': 10,
        'expected_balance': 100,
        'current_balance': 94,
      },
    );

    expect(failure.retrySafe, isTrue);
    expect(failure.details['current_balance'], 94);
    expect(
      apiErrorMessage(failure),
      'The storage quantity changed. Review the latest balance and confirm again.',
    );
  });
}
