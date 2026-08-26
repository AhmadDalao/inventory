import '../../core/models/inventory_models.dart';

class DraftReplayException implements Exception {
  const DraftReplayException(this.message);

  final String message;

  @override
  String toString() => message;
}

List<CartLine> resolveDraftCartLines(
  Map<String, dynamic> payload,
  MobileBootstrap bootstrap, {
  required int sourceStorageId,
}) {
  if (!bootstrap.storages.any((storage) => storage.id == sourceStorageId)) {
    throw const DraftReplayException(
      'You no longer have access to this draft\'s source storage. Discard it or ask the owner to restore access.',
    );
  }

  final rawLines = (payload['lines'] as List?) ?? const [];
  if (rawLines.isEmpty) {
    throw const DraftReplayException('This draft has no item lines.');
  }

  return rawLines.whereType<Map>().map((raw) {
    final line = Map<String, dynamic>.from(raw);
    final itemId = (line['item_id'] as num?)?.toInt() ?? 0;
    InventoryItem? item;
    for (final candidate in bootstrap.items) {
      if (candidate.id == itemId && candidate.storageId == sourceStorageId) {
        item = candidate;
        break;
      }
    }
    if (item == null) {
      throw DraftReplayException(
        'Item #$itemId is no longer available in the selected storage. Review or discard this draft.',
      );
    }

    final presetId = (line['package_preset_id'] as num?)?.toInt();
    ItemPackagePreset? preset;
    if (presetId != null) {
      for (final candidate in item.packagePresets) {
        if (candidate.id == presetId) {
          preset = candidate;
          break;
        }
      }
      if (preset == null) {
        throw DraftReplayException(
          '${item.name}: the saved package preset is no longer active. Review or discard this draft.',
        );
      }
    }

    final quantity =
        (line['input_quantity'] as num? ?? line['quantity'] as num? ?? 0)
            .toDouble();
    if (quantity <= 0) {
      throw DraftReplayException(
        '${item.name}: enter a quantity greater than zero before retrying.',
      );
    }

    return CartLine(
      item: item,
      quantity: quantity,
      packageLabel:
          preset?.label ??
          line['package_label'] as String? ??
          item.canonicalUnit,
      packageMultiplier:
          preset?.piecesPerUnit ??
          (line['package_multiplier'] as num? ?? 1).toDouble(),
      packagePresetId: presetId,
      expectedBalance:
          (line['expected_balance'] as num?)?.toDouble() ?? item.quantity,
      reasonCode: line['reason'] as String?,
      customReason: line['custom_reason'] as String?,
    );
  }).toList();
}
