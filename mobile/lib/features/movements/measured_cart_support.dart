import '../../core/models/inventory_models.dart';

ItemPackagePreset? matchedPackagePreset(InventoryItem item) {
  final matchedId = item.matchedPackagePresetId;
  if (matchedId == null) return null;
  for (final preset in item.packagePresets) {
    if (preset.id == matchedId) return preset;
  }
  return null;
}

CartLine measuredLineForItem(InventoryItem item) {
  final preset = matchedPackagePreset(item);
  return CartLine(
    item: item,
    quantity: 1,
    packageLabel: preset?.label ?? item.canonicalUnit,
    packageMultiplier: preset?.piecesPerUnit ?? 1,
    packagePresetId: preset?.id,
    expectedBalance: item.quantity,
  );
}

void addOrIncrementMeasuredLine(List<CartLine> lines, InventoryItem item) {
  final incoming = measuredLineForItem(item);
  final index = lines.indexWhere(
    (line) =>
        line.item.id == item.id &&
        line.packagePresetId == incoming.packagePresetId,
  );
  if (index < 0) {
    lines.add(incoming);
    return;
  }
  lines[index] = lines[index].copyWith(quantity: lines[index].quantity + 1);
}

String measuredNumber(double value) => value == value.roundToDouble()
    ? value.toInt().toString()
    : value
          .toStringAsFixed(3)
          .replaceFirst(RegExp(r'0+$'), '')
          .replaceFirst(RegExp(r'\.$'), '');
