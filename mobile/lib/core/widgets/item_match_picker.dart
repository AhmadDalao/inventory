import 'package:flutter/material.dart';

import '../models/inventory_models.dart';
import '../theme/kona_theme.dart';
import 'item_widgets.dart';
import 'kona_page.dart';

/// Keeps genuinely different scan targets while removing duplicate API rows.
/// Package and storage identity are part of the key because both affect stock.
List<InventoryItem> normalizeInventoryMatches(Iterable<InventoryItem> matches) {
  final unique = <String, InventoryItem>{};
  for (final item in matches) {
    final key =
        '${item.id}:${item.storageId}:${item.matchedPackagePresetId ?? 0}';
    unique.putIfAbsent(key, () => item);
  }
  return unique.values.toList(growable: false);
}

Future<InventoryItem?> chooseInventoryMatch(
  BuildContext context,
  Iterable<InventoryItem> matches, {
  required String scannedCode,
}) async {
  final options = normalizeInventoryMatches(matches);
  if (options.isEmpty) return null;
  if (options.length == 1) return options.single;

  return showModalBottomSheet<InventoryItem>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (context) =>
        _ItemMatchSheet(scannedCode: scannedCode, options: options),
  );
}

class _ItemMatchSheet extends StatelessWidget {
  const _ItemMatchSheet({required this.scannedCode, required this.options});

  final String scannedCode;
  final List<InventoryItem> options;

  @override
  Widget build(BuildContext context) => FractionallySizedBox(
    heightFactor: .72,
    child: Padding(
      padding: const EdgeInsets.fromLTRB(18, 16, 18, 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const SectionHeading(
            eyebrow: 'Scan match',
            title: 'Choose the exact stock line',
          ),
          const SizedBox(height: 6),
          Text(
            '“$scannedCode” matched more than one item, package, or storage. '
            'Nothing changes until you choose one.',
            style: const TextStyle(color: KonaColors.muted),
          ),
          const SizedBox(height: 14),
          Expanded(
            child: ListView.separated(
              itemCount: options.length,
              separatorBuilder: (_, _) => const SizedBox(height: 9),
              itemBuilder: (context, index) {
                final item = options[index];
                final package = _matchedPackage(item);
                return Material(
                  color: KonaColors.surface,
                  borderRadius: BorderRadius.circular(18),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(18),
                    onTap: () => Navigator.of(context).pop(item),
                    child: Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        border: Border.all(color: KonaColors.line),
                        borderRadius: BorderRadius.circular(18),
                      ),
                      child: Row(
                        children: [
                          ItemThumbnail(item: item, size: 48),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  item.name,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w700,
                                    fontSize: 16,
                                  ),
                                ),
                                Text(
                                  '${item.sku} · ${item.storageName}',
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                    color: KonaColors.muted,
                                  ),
                                ),
                                if (package != null)
                                  Text(
                                    '${package.label} · ×${_number(package.piecesPerUnit)} ${item.canonicalUnit}',
                                    style: const TextStyle(
                                      color: KonaColors.goldDark,
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                              ],
                            ),
                          ),
                          const SizedBox(width: 8),
                          QuantityBadge(
                            quantity: item.quantity,
                            unit: item.canonicalUnit,
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    ),
  );

  ItemPackagePreset? _matchedPackage(InventoryItem item) {
    final matchedId = item.matchedPackagePresetId;
    if (matchedId == null) return null;
    for (final preset in item.packagePresets) {
      if (preset.id == matchedId) return preset;
    }
    return null;
  }

  static String _number(double value) => value == value.roundToDouble()
      ? value.toInt().toString()
      : value.toStringAsFixed(2);
}
