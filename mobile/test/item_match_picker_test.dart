import 'package:flutter_test/flutter_test.dart';
import 'package:inventory_kona/core/models/inventory_models.dart';
import 'package:inventory_kona/core/widgets/item_match_picker.dart';

void main() {
  InventoryItem item({int storageId = 10, int? matchedPackagePresetId}) =>
      InventoryItem(
        id: 15,
        name: 'Blue wristband',
        sku: 'WB-BLUE',
        unit: 'pcs',
        quantity: 100,
        storageId: storageId,
        storageName: storageId == 10 ? 'KONA' : 'KONA Office',
        matchedPackagePresetId: matchedPackagePresetId,
        packagePresets: const [
          ItemPackagePreset(id: 7, label: 'Box', piecesPerUnit: 24),
          ItemPackagePreset(id: 8, label: 'Pack', piecesPerUnit: 10),
        ],
      );

  test('removes duplicate API rows for the same stock target', () {
    final result = normalizeInventoryMatches([item(), item(), item()]);

    expect(result, hasLength(1));
  });

  test('keeps different package matches for explicit selection', () {
    final result = normalizeInventoryMatches([
      item(matchedPackagePresetId: 7),
      item(matchedPackagePresetId: 8),
    ]);

    expect(result, hasLength(2));
  });

  test('keeps the same item in different storages distinct', () {
    final result = normalizeInventoryMatches([
      item(storageId: 10),
      item(storageId: 11),
    ]);

    expect(result, hasLength(2));
  });
}
