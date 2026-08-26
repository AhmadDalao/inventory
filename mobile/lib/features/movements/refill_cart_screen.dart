import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/api/api_client.dart';
import '../../core/data/providers.dart';
import '../../core/models/inventory_models.dart';
import '../../core/theme/kona_theme.dart';
import '../../core/widgets/item_match_picker.dart';
import '../../core/widgets/item_widgets.dart';
import '../../core/widgets/kona_page.dart';
import '../../core/widgets/numeric_input.dart';
import '../../core/widgets/status_widgets.dart';
import 'measured_cart_support.dart';

class RefillCartScreen extends ConsumerStatefulWidget {
  const RefillCartScreen({super.key});

  @override
  ConsumerState<RefillCartScreen> createState() => _RefillCartScreenState();
}

class _RefillCartScreenState extends ConsumerState<RefillCartScreen> {
  final List<CartLine> _lines = [];
  final _reference = TextEditingController();
  final _notes = TextEditingController();
  int? _storageId;
  XFile? _proof;
  bool _submitting = false;

  @override
  void dispose() {
    _reference.dispose();
    _notes.dispose();
    super.dispose();
  }

  void _configure(MobileBootstrap data) {
    _storageId ??= data.defaultStorage?.id;
  }

  void _addItem(InventoryItem item) {
    setState(() => addOrIncrementMeasuredLine(_lines, item));
  }

  Future<void> _changeStorage(int? value) async {
    if (value == null || value == _storageId) return;
    if (_lines.isNotEmpty) {
      final clear = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          icon: const Icon(Icons.warning_amber_rounded),
          title: const Text('Clear this refill cart?'),
          content: const Text(
            'Projected balances belong to the current storage. Clear the cart before switching.',
          ),
          actions: [
            TextButton(
              onPressed: () => context.pop(false),
              child: const Text('Keep storage'),
            ),
            FilledButton(
              onPressed: () => context.pop(true),
              child: const Text('Clear and switch'),
            ),
          ],
        ),
      );
      if (clear != true || !mounted) return;
    }
    setState(() {
      _storageId = value;
      _lines.clear();
    });
  }

  Future<void> _scan() async {
    if (_storageId == null) {
      _message('Select a destination storage before scanning.');
      return;
    }
    try {
      final code = await context.push<String>('/scanner/item');
      if (code == null || !mounted) return;
      final matches = await ref
          .read(inventoryRepositoryProvider)
          .searchItems(code, storageId: _storageId);
      if (!mounted) return;
      if (matches.isEmpty) {
        _message('No assigned item or package matched that code.');
        return;
      }
      final selected = await chooseInventoryMatch(
        context,
        matches,
        scannedCode: code,
      );
      if (selected != null && mounted) _addItem(selected);
    } catch (error) {
      if (mounted) _message(apiErrorMessage(error));
    }
  }

  Future<void> _pickItem() async {
    final data = ref.read(bootstrapProvider).valueOrNull;
    if (data == null) return;
    final selected = await showModalBottomSheet<InventoryItem>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _RefillItemPicker(
        items: data.items
            .where((item) => _storageId == null || item.storageId == _storageId)
            .toList(),
      ),
    );
    if (selected != null) _addItem(selected);
  }

  bool _proofRequired(MobileBootstrap data) =>
      data.requireRefillProof ||
      _lines.any((line) => line.item.requiresRefillProof);

  String? _validationMessage(MobileBootstrap data, {bool requireProof = true}) {
    if (_storageId == null) return 'Select a storage to refill.';
    if (_lines.isEmpty) return 'Scan or add at least one item.';
    for (final line in _lines) {
      if (line.quantity <= 0 || line.baseQuantity <= 0) {
        return 'Enter a positive quantity for ${line.item.name}.';
      }
    }
    if (requireProof && _proofRequired(data) && _proof == null) {
      return 'A proof image is required before this refill can be submitted.';
    }
    return null;
  }

  Future<void> _pickProof() async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      builder: (context) => SafeArea(
        child: Wrap(
          children: [
            ListTile(
              leading: const Icon(Icons.camera_alt_outlined),
              title: const Text('Take photo'),
              onTap: () => context.pop(ImageSource.camera),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Choose from gallery'),
              onTap: () => context.pop(ImageSource.gallery),
            ),
          ],
        ),
      ),
    );
    if (source == null || !mounted) return;
    final image = await ImagePicker().pickImage(
      source: source,
      imageQuality: 82,
    );
    if (mounted && image != null) setState(() => _proof = image);
  }

  Future<void> _submit(MobileBootstrap data) async {
    final validation = _validationMessage(data);
    if (validation != null) {
      _message(validation);
      return;
    }
    setState(() => _submitting = true);
    try {
      final receipt = await ref
          .read(inventoryRepositoryProvider)
          .submitRestock(
            storageId: _storageId!,
            lines: _lines,
            reference: _reference.text.trim().isEmpty
                ? null
                : _reference.text.trim(),
            notes: _notes.text.trim().isEmpty ? null : _notes.text.trim(),
            proofPath: _proof?.path,
          );
      if (!mounted) return;
      await ref.read(bootstrapProvider.notifier).applyOperationReceipt(receipt);
      if (!mounted) return;
      ref.invalidate(mobileOperationsProvider);
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          icon: const Icon(
            Icons.check_circle,
            color: KonaColors.success,
            size: 42,
          ),
          title: const Text('Refill posted'),
          content: Text(
            '${receipt.reference}\nThe server converted every package and returned authoritative balances.',
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
    } catch (error) {
      if (mounted) _message(apiErrorMessage(error));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _saveDraft(MobileBootstrap data) async {
    final validation = _validationMessage(data, requireProof: false);
    if (validation != null) {
      _message(validation);
      return;
    }
    await ref
        .read(draftStoreProvider)
        .save(
          type: 'restock',
          title:
              'Refill · ${measuredNumber(_lines.fold<double>(0, (sum, line) => sum + line.baseQuantity))} base units',
          payload: {
            'schema_version': 3,
            'storage_id': _storageId,
            'reference': _reference.text.trim(),
            'notes': _notes.text.trim(),
            'proof_path': _proof?.path,
            'lines': [
              for (final line in _lines)
                {
                  'item_id': line.item.id,
                  'quantity': line.quantity,
                  'input_quantity': line.quantity,
                  'package_preset_id': line.packagePresetId,
                  'package_label': line.packageLabel,
                  'package_multiplier': line.packageMultiplier,
                  'base_quantity': line.baseQuantity,
                  'base_unit': line.item.canonicalUnit,
                  'expected_balance':
                      line.expectedBalance ?? line.item.quantity,
                },
            ],
          },
        );
    if (mounted) _message('Refill draft saved. Stock was not posted.');
  }

  void _message(String message) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    final data = ref.watch(bootstrapProvider).valueOrNull;
    if (data != null) _configure(data);
    final total = _lines.fold<double>(
      0,
      (sum, line) => sum + line.baseQuantity,
    );
    return KonaPage(
      eyebrow: 'Privileged stock entry',
      title: 'Refill storage',
      description:
          'Scan packages or add catalog items, review conversions, attach proof, then let the server post stock.',
      trailing: IconButton.filledTonal(
        onPressed: _scan,
        icon: const Icon(Icons.qr_code_scanner),
      ),
      bottomAction: Row(
        children: [
          Expanded(
            child: OutlinedButton.icon(
              onPressed: data == null || _lines.isEmpty
                  ? null
                  : () => _saveDraft(data),
              icon: const Icon(Icons.save_outlined),
              label: const Text('Save draft'),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            flex: 2,
            child: ElevatedButton.icon(
              onPressed: _submitting || data == null || _lines.isEmpty
                  ? null
                  : () => _submit(data),
              icon: const Icon(Icons.add_business_outlined),
              label: Text(
                _submitting
                    ? 'Validating'
                    : 'Post ${measuredNumber(total)} base units',
              ),
            ),
          ),
        ],
      ),
      children: [
        DropdownButtonFormField<int>(
          initialValue: _storageId,
          decoration: const InputDecoration(
            labelText: 'Destination storage',
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
          onChanged: _changeStorage,
        ),
        KonaSectionCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SectionHeading(
                eyebrow: 'Review cart',
                title: '${_lines.length} item${_lines.length == 1 ? '' : 's'}',
                trailing: TextButton.icon(
                  onPressed: _pickItem,
                  icon: const Icon(Icons.add),
                  label: const Text('Add'),
                ),
              ),
              const SizedBox(height: 12),
              if (_lines.isEmpty)
                const EmptyState(
                  icon: Icons.inventory_2_outlined,
                  title: 'No refill lines yet',
                  message:
                      'Scan an item/package barcode or select an existing catalog item.',
                )
              else
                ..._lines.asMap().entries.map(
                  (entry) => _RefillLineEditor(
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
                eyebrow: 'Evidence',
                title: 'Reference and proof',
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _reference,
                decoration: const InputDecoration(
                  labelText: 'Reference (optional)',
                  hintText: 'Invoice, delivery, or supplier reference',
                  prefixIcon: Icon(Icons.receipt_long_outlined),
                ),
              ),
              const SizedBox(height: 12),
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
                onPressed: _pickProof,
                icon: Icon(
                  _proof == null
                      ? Icons.add_a_photo_outlined
                      : Icons.check_circle_outline,
                ),
                label: Text(
                  _proof == null
                      ? (data != null && _proofRequired(data))
                            ? 'Attach refill proof · required'
                            : 'Attach refill proof · optional'
                      : 'Proof attached',
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _RefillLineEditor extends StatelessWidget {
  const _RefillLineEditor({
    required this.line,
    required this.onChanged,
    required this.onRemove,
  });

  final CartLine line;
  final ValueChanged<CartLine> onChanged;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final choices = <_RefillPackageChoice>[
      _RefillPackageChoice(
        key: 'base',
        label: line.item.canonicalUnit,
        multiplier: 1,
      ),
      ...line.item.packagePresets.map(
        (preset) => _RefillPackageChoice(
          key: 'preset-${preset.id}',
          presetId: preset.id,
          label: preset.label,
          multiplier: preset.piecesPerUnit,
        ),
      ),
    ];
    final selected = choices.firstWhere(
      (choice) => choice.presetId == line.packagePresetId,
      orElse: () => choices.first,
    );
    final projected = line.item.quantity + line.baseQuantity;
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: KonaColors.canvas,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: KonaColors.line),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
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
            LayoutBuilder(
              builder: (context, constraints) {
                final packageField = DropdownButtonFormField<String>(
                  key: ValueKey(
                    'refill-${line.item.id}-${selected.key}-${choices.length}',
                  ),
                  initialValue: selected.key,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Unit / package',
                  ),
                  items: choices
                      .map(
                        (choice) => DropdownMenuItem(
                          value: choice.key,
                          child: Text(
                            '${choice.label} · ×${measuredNumber(choice.multiplier)}',
                          ),
                        ),
                      )
                      .toList(),
                  onChanged: (value) {
                    final choice = choices.firstWhere(
                      (candidate) => candidate.key == value,
                      orElse: () => choices.first,
                    );
                    onChanged(
                      line.copyWith(
                        packageLabel: choice.label,
                        packageMultiplier: choice.multiplier,
                        packagePresetId: choice.presetId,
                        clearPackagePreset: choice.presetId == null,
                      ),
                    );
                  },
                );
                final quantityField = TextFormField(
                  initialValue: measuredNumber(line.quantity),
                  onTap: selectAllNumericTextOnTap,
                  keyboardType: const TextInputType.numberWithOptions(
                    decimal: true,
                  ),
                  decoration: const InputDecoration(
                    labelText: 'Quantity to add',
                  ),
                  onChanged: (value) => onChanged(
                    line.copyWith(quantity: double.tryParse(value) ?? 0),
                  ),
                );
                if (constraints.maxWidth < 520) {
                  return Column(
                    children: [
                      packageField,
                      const SizedBox(height: 9),
                      quantityField,
                    ],
                  );
                }
                return Row(
                  children: [
                    Expanded(child: packageField),
                    const SizedBox(width: 9),
                    Expanded(child: quantityField),
                  ],
                );
              },
            ),
            const SizedBox(height: 9),
            Wrap(
              alignment: WrapAlignment.spaceBetween,
              spacing: 12,
              runSpacing: 6,
              children: [
                Text(
                  '${measuredNumber(line.quantity)} × ${selected.label} = ${measuredNumber(line.baseQuantity)} ${line.item.canonicalUnit}',
                  style: const TextStyle(
                    fontWeight: FontWeight.w700,
                    color: KonaColors.goldDark,
                  ),
                ),
                Text(
                  'Projected: ${measuredNumber(projected)} ${line.item.canonicalUnit}',
                  style: const TextStyle(color: KonaColors.muted),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _RefillPackageChoice {
  const _RefillPackageChoice({
    required this.key,
    required this.label,
    required this.multiplier,
    this.presetId,
  });

  final String key;
  final String label;
  final double multiplier;
  final int? presetId;
}

class _RefillItemPicker extends StatefulWidget {
  const _RefillItemPicker({required this.items});

  final List<InventoryItem> items;

  @override
  State<_RefillItemPicker> createState() => _RefillItemPickerState();
}

class _RefillItemPickerState extends State<_RefillItemPicker> {
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
          const SectionHeading(eyebrow: 'Catalog', title: 'Add refill item'),
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
