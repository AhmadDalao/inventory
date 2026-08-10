import 'package:flutter_test/flutter_test.dart';
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
}
