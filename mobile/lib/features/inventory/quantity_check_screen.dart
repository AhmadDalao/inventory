import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/data/providers.dart';
import '../../core/models/inventory_models.dart';
import '../../core/theme/kona_theme.dart';
import '../../core/widgets/item_widgets.dart';
import '../../core/widgets/kona_page.dart';
import '../../core/widgets/status_widgets.dart';

class QuantityCheckScreen extends ConsumerStatefulWidget {
  const QuantityCheckScreen({super.key});

  @override
  ConsumerState<QuantityCheckScreen> createState() =>
      _QuantityCheckScreenState();
}

class _QuantityCheckScreenState extends ConsumerState<QuantityCheckScreen> {
  final _search = TextEditingController();
  List<InventoryItem> _results = const [];
  int? _storageId;
  bool _loading = false;

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _lookup([String? code]) async {
    if (code != null) _search.text = code;
    setState(() => _loading = true);
    try {
      final results = await ref
          .read(inventoryRepositoryProvider)
          .searchItems(_search.text, storageId: _storageId);
      if (mounted) setState(() => _results = results);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _scan() async {
    final code = await context.push<String>('/scanner/lookup');
    if (code != null) await _lookup(code);
  }

  @override
  Widget build(BuildContext context) {
    final bootstrap = ref.watch(bootstrapProvider).valueOrNull;
    _storageId ??= bootstrap?.defaultStorage?.id;
    return KonaPage(
      eyebrow: 'Inventory lookup',
      title: 'Quantity check',
      description:
          'Scan or search. Quantities are shown only for the selected assigned storage.',
      children: [
        KonaSectionCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              DropdownButtonFormField<int>(
                initialValue: _storageId,
                decoration: const InputDecoration(
                  labelText: 'Storage',
                  prefixIcon: Icon(Icons.warehouse_outlined),
                ),
                items: (bootstrap?.storages ?? const [])
                    .map(
                      (storage) => DropdownMenuItem(
                        value: storage.id,
                        child: Text(storage.name),
                      ),
                    )
                    .toList(),
                onChanged: (value) => setState(() => _storageId = value),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _search,
                onSubmitted: (_) => _lookup(),
                decoration: InputDecoration(
                  labelText: 'Barcode, SKU, or item name',
                  prefixIcon: const Icon(Icons.search),
                  suffixIcon: IconButton(
                    onPressed: _scan,
                    icon: const Icon(Icons.qr_code_scanner),
                  ),
                ),
              ),
              const SizedBox(height: 12),
              ElevatedButton.icon(
                onPressed: _loading ? null : _lookup,
                icon: const Icon(Icons.search),
                label: Text(_loading ? 'Looking up' : 'Check quantity'),
              ),
            ],
          ),
        ),
        if (_results.isEmpty)
          const KonaSectionCard(
            child: EmptyState(
              icon: Icons.manage_search,
              title: 'Ready to check',
              message: 'Scan a label or type an item name.',
            ),
          )
        else
          KonaSectionCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SectionHeading(
                  eyebrow: 'Matches',
                  title:
                      '${_results.length} item${_results.length == 1 ? '' : 's'}',
                ),
                const SizedBox(height: 12),
                ..._results.map(
                  (item) => Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: InventoryItemTile(
                      item: item,
                      trailing: QuantityBadge(
                        quantity: item.quantity,
                        unit: item.unit,
                        warning: item.quantity <= item.reorderLevel,
                      ),
                    ),
                  ),
                ),
                const Row(
                  children: [
                    Icon(
                      Icons.cloud_done_outlined,
                      size: 16,
                      color: KonaColors.success,
                    ),
                    SizedBox(width: 6),
                    Text(
                      'Current server-validated quantity',
                      style: TextStyle(color: KonaColors.muted, fontSize: 12),
                    ),
                  ],
                ),
              ],
            ),
          ),
      ],
    );
  }
}
