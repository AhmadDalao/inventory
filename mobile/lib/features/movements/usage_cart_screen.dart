import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/data/providers.dart';
import '../../core/models/inventory_models.dart';
import '../../core/theme/kona_theme.dart';
import '../../core/widgets/item_widgets.dart';
import '../../core/widgets/kona_page.dart';

class UsageCartScreen extends ConsumerStatefulWidget {
  const UsageCartScreen({super.key});

  @override
  ConsumerState<UsageCartScreen> createState() => _UsageCartScreenState();
}

class _UsageCartScreenState extends ConsumerState<UsageCartScreen> {
  final List<CartLine> _lines = [];
  final _notes = TextEditingController();
  String _reason = 'online';
  int? _storageId;
  XFile? _proof;
  bool _submitting = false;

  static const reasons = [
    'online',
    'walkin',
    'event',
    'sport',
    'damage',
    'complimentary',
    'no_show',
    'other',
  ];

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  void _seed(MobileBootstrap data) {
    if (_lines.isNotEmpty || data.items.isEmpty) return;
    final item = data.items.firstWhere(
      (candidate) => candidate.storageId == data.defaultStorage?.id,
      orElse: () => data.items.first,
    );
    _lines.add(CartLine(item: item, quantity: 3));
  }

  void _addItem(InventoryItem item) {
    final index = _lines.indexWhere((line) => line.item.id == item.id);
    setState(() {
      if (index >= 0) {
        _lines[index] = _lines[index].copyWith(
          quantity: _lines[index].quantity + 1,
        );
      } else {
        _lines.add(CartLine(item: item, quantity: 1));
      }
    });
  }

  Future<void> _scan() async {
    final code = await context.push<String>('/scanner/item');
    if (code == null) return;
    final matches = await ref
        .read(inventoryRepositoryProvider)
        .searchItems(code, storageId: _storageId);
    if (matches.isNotEmpty) _addItem(matches.first);
  }

  Future<void> _pickItem() async {
    final data = ref.read(bootstrapProvider).valueOrNull;
    if (data == null) return;
    final selected = await showModalBottomSheet<InventoryItem>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _ItemPicker(
        items: data.items
            .where((item) => _storageId == null || item.storageId == _storageId)
            .toList(),
      ),
    );
    if (selected != null) _addItem(selected);
  }

  Future<void> _submit() async {
    if (_lines.isEmpty || _storageId == null) return;
    setState(() => _submitting = true);
    try {
      final receipt = await ref
          .read(inventoryRepositoryProvider)
          .submitUsage(
            storageId: _storageId!,
            lines: _lines,
            reason: _reason,
            notes: _notes.text.trim().isEmpty ? null : _notes.text.trim(),
            proofPath: _proof?.path,
          );
      if (!mounted) return;
      await showDialog<void>(
        context: context,
        builder: (_) => AlertDialog(
          icon: const Icon(
            Icons.check_circle,
            color: KonaColors.success,
            size: 42,
          ),
          title: const Text('Usage submitted'),
          content: Text(
            '${receipt.reference}\nThe server validated the balance before posting.',
          ),
          actions: [
            FilledButton(
              onPressed: () => context.pop(),
              child: const Text('Close'),
            ),
          ],
        ),
      );
      if (mounted) context.go('/home');
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _saveDraft() async {
    if (_lines.isEmpty || _storageId == null) return;
    await ref
        .read(draftStoreProvider)
        .save(
          type: 'usage',
          title:
              'Usage · ${_number(_lines.fold<double>(0, (sum, line) => sum + line.pieceQuantity))} units',
          payload: {
            'storage_id': _storageId,
            'reason': _reason,
            'notes': _notes.text.trim(),
            'proof_path': _proof?.path,
            'lines': [
              for (final line in _lines)
                {
                  'item_id': line.item.id,
                  'quantity': line.quantity,
                  'package_label': line.packageLabel,
                  'package_multiplier': line.packageMultiplier,
                  'expected_balance':
                      line.expectedBalance ?? line.item.quantity,
                },
            ],
          },
        );
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('Draft saved on this device. Stock was not posted.'),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final data = ref.watch(bootstrapProvider).valueOrNull;
    if (data != null) {
      _storageId ??= data.defaultStorage?.id;
      _seed(data);
    }
    final total = _lines.fold<double>(
      0,
      (sum, line) => sum + line.pieceQuantity,
    );
    return KonaPage(
      eyebrow: 'Review before posting',
      title: 'Usage cart',
      description:
          'Repeated scans increment the same item. Package conversions are visible before confirmation.',
      trailing: IconButton.filledTonal(
        onPressed: _scan,
        icon: const Icon(Icons.qr_code_scanner),
      ),
      bottomAction: Row(
        children: [
          Expanded(
            child: OutlinedButton.icon(
              onPressed: _lines.isEmpty ? null : _saveDraft,
              icon: const Icon(Icons.save_outlined),
              label: const Text('Save draft'),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            flex: 2,
            child: ElevatedButton.icon(
              onPressed: _submitting || _lines.isEmpty ? null : _submit,
              icon: const Icon(Icons.shield_outlined),
              label: Text(
                _submitting ? 'Validating' : 'Submit ${_number(total)} units',
              ),
            ),
          ),
        ],
      ),
      children: [
        DropdownButtonFormField<int>(
          initialValue: _storageId,
          decoration: const InputDecoration(
            labelText: 'Source storage',
            prefixIcon: Icon(Icons.warehouse_outlined),
          ),
          items: (data?.storages ?? const [])
              .map(
                (storage) => DropdownMenuItem(
                  value: storage.id,
                  child: Text(storage.name),
                ),
              )
              .toList(),
          onChanged: (value) => setState(() => _storageId = value),
        ),
        KonaSectionCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SectionHeading(
                eyebrow: 'Cart',
                title: '${_lines.length} item${_lines.length == 1 ? '' : 's'}',
                trailing: TextButton.icon(
                  onPressed: _pickItem,
                  icon: const Icon(Icons.add),
                  label: const Text('Add'),
                ),
              ),
              const SizedBox(height: 12),
              if (_lines.isEmpty)
                const Text('Scan or add an item to begin.')
              else
                ..._lines.asMap().entries.map(
                  (entry) => _CartLineEditor(
                    line: entry.value,
                    onChanged: (line) =>
                        setState(() => _lines[entry.key] = line),
                    onRemove: () => setState(() => _lines.removeAt(entry.key)),
                  ),
                ),
            ],
          ),
        ),
        KonaSectionCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SectionHeading(
                eyebrow: 'Accountability',
                title: 'Usage details',
              ),
              const SizedBox(height: 13),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: reasons
                    .map(
                      (reason) => ChoiceChip(
                        label: Text(_label(reason)),
                        selected: _reason == reason,
                        onSelected: (_) => setState(() => _reason = reason),
                      ),
                    )
                    .toList(),
              ),
              const SizedBox(height: 13),
              TextField(
                controller: _notes,
                maxLines: 3,
                decoration: const InputDecoration(
                  labelText: 'Notes (optional)',
                  prefixIcon: Icon(Icons.notes_outlined),
                ),
              ),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: () async {
                  final image = await ImagePicker().pickImage(
                    source: ImageSource.camera,
                    imageQuality: 82,
                  );
                  if (mounted) setState(() => _proof = image);
                },
                icon: const Icon(Icons.camera_alt_outlined),
                label: Text(
                  _proof == null ? 'Add proof image' : 'Proof attached',
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  static String _label(String value) => value
      .split('_')
      .map((part) => '${part[0].toUpperCase()}${part.substring(1)}')
      .join(' ');
  String _number(double value) => value == value.roundToDouble()
      ? value.toInt().toString()
      : value.toStringAsFixed(2);
}

class _CartLineEditor extends StatelessWidget {
  const _CartLineEditor({
    required this.line,
    required this.onChanged,
    required this.onRemove,
  });
  final CartLine line;
  final ValueChanged<CartLine> onChanged;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: KonaColors.canvas,
        borderRadius: BorderRadius.circular(18),
      ),
      child: Column(
        children: [
          InventoryItemTile(
            item: line.item,
            compact: true,
            trailing: IconButton(
              onPressed: onRemove,
              icon: const Icon(Icons.close, color: KonaColors.danger),
            ),
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: DropdownButtonFormField<String>(
                  initialValue: line.packageLabel,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Unit / package',
                  ),
                  items: const [
                    DropdownMenuItem(
                      value: 'Pieces',
                      child: Text('Pieces · ×1'),
                    ),
                    DropdownMenuItem(value: 'Bag', child: Text('Bag · ×10')),
                    DropdownMenuItem(value: 'Box', child: Text('Box · ×50')),
                    DropdownMenuItem(
                      value: 'Package',
                      child: Text('Package · ×100'),
                    ),
                  ],
                  onChanged: (value) {
                    final multiplier = switch (value) {
                      'Bag' => 10.0,
                      'Box' => 50.0,
                      'Package' => 100.0,
                      _ => 1.0,
                    };
                    onChanged(
                      line.copyWith(
                        packageLabel: value,
                        packageMultiplier: multiplier,
                      ),
                    );
                  },
                ),
              ),
              const SizedBox(width: 9),
              Expanded(
                child: TextFormField(
                  initialValue: _format(line.quantity),
                  keyboardType: const TextInputType.numberWithOptions(
                    decimal: true,
                  ),
                  decoration: const InputDecoration(labelText: 'Quantity'),
                  onChanged: (value) => onChanged(
                    line.copyWith(quantity: double.tryParse(value) ?? 0),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 7),
          Align(
            alignment: Alignment.centerRight,
            child: Text(
              '${_format(line.pieceQuantity)} ${line.item.unit} total',
              style: const TextStyle(
                fontWeight: FontWeight.w700,
                color: KonaColors.goldDark,
              ),
            ),
          ),
        ],
      ),
    ),
  );

  String _format(double value) => value == value.roundToDouble()
      ? value.toInt().toString()
      : value.toStringAsFixed(2);
}

class _ItemPicker extends StatefulWidget {
  const _ItemPicker({required this.items});
  final List<InventoryItem> items;

  @override
  State<_ItemPicker> createState() => _ItemPickerState();
}

class _ItemPickerState extends State<_ItemPicker> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final items = widget.items
        .where(
          (item) => '${item.name} ${item.sku} ${item.barcode ?? ''}'
              .toLowerCase()
              .contains(_query.toLowerCase()),
        )
        .toList();
    return Padding(
      padding: EdgeInsets.fromLTRB(
        18,
        16,
        18,
        MediaQuery.viewInsetsOf(context).bottom + 20,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const SectionHeading(eyebrow: 'Catalog', title: 'Add item'),
          const SizedBox(height: 12),
          TextField(
            autofocus: true,
            onChanged: (value) => setState(() => _query = value),
            decoration: const InputDecoration(
              hintText: 'Search item, SKU, or barcode',
              prefixIcon: Icon(Icons.search),
            ),
          ),
          const SizedBox(height: 12),
          Flexible(
            child: ListView.separated(
              shrinkWrap: true,
              itemCount: items.length,
              separatorBuilder: (_, _) => const SizedBox(height: 8),
              itemBuilder: (_, index) => InventoryItemTile(
                item: items[index],
                compact: true,
                trailing: QuantityBadge(
                  quantity: items[index].quantity,
                  unit: items[index].unit,
                ),
                onTap: () => context.pop(items[index]),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
