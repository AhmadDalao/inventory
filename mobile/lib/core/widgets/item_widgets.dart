import 'package:flutter/material.dart';

import '../models/inventory_models.dart';
import '../theme/kona_theme.dart';

class ItemThumbnail extends StatelessWidget {
  const ItemThumbnail({super.key, required this.item, this.size = 54});

  final InventoryItem item;
  final double size;

  @override
  Widget build(BuildContext context) {
    final imageUrl = item.imageUrl;
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: _colorFor(item.id),
        borderRadius: BorderRadius.circular(size * .3),
        border: Border.all(color: KonaColors.line),
      ),
      clipBehavior: Clip.antiAlias,
      child: imageUrl != null && imageUrl.isNotEmpty
          ? Image.network(
              imageUrl,
              fit: BoxFit.cover,
              errorBuilder: (_, _, _) => _fallback(),
            )
          : _fallback(),
    );
  }

  Widget _fallback() => Center(
    child: Text(
      item.name.characters.first.toUpperCase(),
      style: TextStyle(
        color: Colors.white,
        fontSize: size * .32,
        fontWeight: FontWeight.w700,
      ),
    ),
  );

  Color _colorFor(int seed) {
    const palette = [
      Color(0xFF145DA0),
      Color(0xFF7C2433),
      Color(0xFF126A57),
      Color(0xFFD49B00),
      Color(0xFF6F4A8E),
      Color(0xFF546E7A),
    ];
    return palette[seed.abs() % palette.length];
  }
}

class InventoryItemTile extends StatelessWidget {
  const InventoryItemTile({
    super.key,
    required this.item,
    this.trailing,
    this.onTap,
    this.compact = false,
  });

  final InventoryItem item;
  final Widget? trailing;
  final VoidCallback? onTap;
  final bool compact;

  @override
  Widget build(BuildContext context) => Material(
    color: KonaColors.surface,
    borderRadius: BorderRadius.circular(18),
    child: InkWell(
      borderRadius: BorderRadius.circular(18),
      onTap: onTap,
      child: Container(
        padding: EdgeInsets.all(compact ? 11 : 14),
        decoration: BoxDecoration(
          border: Border.all(color: KonaColors.line),
          borderRadius: BorderRadius.circular(18),
        ),
        child: Row(
          children: [
            ItemThumbnail(item: item, size: compact ? 44 : 56),
            const SizedBox(width: 13),
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
                  const SizedBox(height: 2),
                  Text(
                    '${item.sku} · ${item.unit}',
                    style: const TextStyle(color: KonaColors.muted),
                  ),
                  if (!compact) ...[
                    const SizedBox(height: 5),
                    Text(
                      item.storageName,
                      style: const TextStyle(
                        color: KonaColors.muted,
                        fontSize: 12,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            if (trailing != null) ...[const SizedBox(width: 10), trailing!],
          ],
        ),
      ),
    ),
  );
}

class QuantityBadge extends StatelessWidget {
  const QuantityBadge({
    super.key,
    required this.quantity,
    required this.unit,
    this.warning = false,
  });

  final double quantity;
  final String unit;
  final bool warning;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 7),
    decoration: BoxDecoration(
      color: warning ? const Color(0xFFFFE7E2) : KonaColors.soft,
      borderRadius: BorderRadius.circular(999),
    ),
    child: Text(
      '${_format(quantity)} $unit',
      style: TextStyle(
        fontWeight: FontWeight.w700,
        color: warning ? KonaColors.danger : KonaColors.ink,
      ),
    ),
  );

  String _format(double value) => value == value.roundToDouble()
      ? value.toInt().toString()
      : value.toStringAsFixed(2);
}
