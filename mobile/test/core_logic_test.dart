import 'package:flutter_test/flutter_test.dart';
import 'package:inventory_kona/core/api/api_client.dart';
import 'package:inventory_kona/core/logic/handover_reconciliation.dart';
import 'package:inventory_kona/core/logic/scan_debouncer.dart';
import 'package:inventory_kona/core/models/inventory_models.dart';

void main() {
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
      permissions: {'items.view', 'movements.usage'},
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
      permissions: {'items.view', 'movements.usage'},
      capabilities: {'usage'},
      storageIds: {10},
    );

    final updated = original.mergeSyncDelta(delta);

    expect(updated.items.single.quantity, 94);
    expect(updated.defaultStorage?.id, 10);
    expect(updated.canUseStock, isTrue);
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
