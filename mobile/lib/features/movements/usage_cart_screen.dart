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

class UsageCartScreen extends ConsumerStatefulWidget {
  const UsageCartScreen({super.key});

  @override
  ConsumerState<UsageCartScreen> createState() => _UsageCartScreenState();
}

class _UsageCartScreenState extends ConsumerState<UsageCartScreen> {
  final List<CartLine> _lines = [];
  final _notes = TextEditingController();
  final _defaultCustomReason = TextEditingController();
  String _defaultReason = 'online';
  int? _storageId;
  XFile? _proof;
  bool _catalogInitialized = false;
  bool _submitting = false;

  @override
  void dispose() {
    _notes.dispose();
    _defaultCustomReason.dispose();
    super.dispose();
  }

  void _configure(MobileBootstrap data) {
    _storageId ??= data.defaultStorage?.id;
    if (_catalogInitialized) return;
    final reasons = data.usageReasons;
    if (!reasons.any((reason) => reason.code == _defaultReason)) {
      _defaultReason = reasons.first.code;
    }
    _catalogInitialized = true;
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
          title: const Text('Clear this cart?'),
          content: const Text(
            'Cart quantities belong to the current storage. Clear them before switching storage.',
          ),
          actions: [
            TextButton(
              onPressed: () => context.pop(false),
              child: const Text('Keep current storage'),
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
      _message('Select a source storage before scanning.');
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
        _message('No assigned item matched that code.');
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
      builder: (_) => _ItemPicker(
        items: data.items
            .where((item) => _storageId == null || item.storageId == _storageId)
            .toList(),
      ),
    );
    if (selected != null) _addItem(selected);
  }

  UsageReason? _reason(List<UsageReason> reasons, String code) {
    for (final reason in reasons) {
      if (reason.code == UsageReason.normalizeCode(code)) return reason;
    }
    return null;
  }

  String? _validationMessage(MobileBootstrap data, {bool requireProof = true}) {
    if (_storageId == null) return 'Select a source storage.';
    if (_lines.isEmpty) return 'Scan or add at least one item.';
    final proofRequired =
        data.requireUsageProof ||
        _lines.any((line) => line.item.requiresUsageProof);
    if (requireProof && proofRequired && _proof == null) {
      return 'A proof image is required before this usage can be submitted.';
    }

    final reasons = data.usageReasons;
    final defaultDefinition = _reason(reasons, _defaultReason);
    if (defaultDefinition == null) return 'Pick an active usage reason.';
    if (defaultDefinition.requiresCustomText &&
        _defaultCustomReason.text.trim().isEmpty) {
      return 'Describe the default Other reason.';
    }

    for (final line in _lines) {
      if (line.quantity <= 0 || line.baseQuantity <= 0) {
        return 'Enter a positive quantity for ${line.item.name}.';
      }
      if (line.baseQuantity > line.item.quantity) {
        return '${line.item.name} exceeds the last synced storage balance.';
      }
      final code = line.reasonCode ?? _defaultReason;
      final definition = _reason(reasons, code);
      if (definition == null) {
        return 'Pick an active reason for ${line.item.name}.';
      }
      final custom = line.reasonCode == null
          ? _defaultCustomReason.text
          : line.customReason ?? '';
      if (definition.requiresCustomText && custom.trim().isEmpty) {
        return 'Describe the Other reason for ${line.item.name}.';
      }
    }
    return null;
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
          .submitUsage(
            storageId: _storageId!,
            lines: _lines,
            defaultReason: _defaultReason,
            defaultCustomReason: _defaultCustomReason.text.trim().isEmpty
                ? null
                : _defaultCustomReason.text.trim(),
            notes: _notes.text.trim().isEmpty ? null : _notes.text.trim(),
            proofPath: _proof?.path,
          );
      if (!mounted) return;
      await ref.read(bootstrapProvider.notifier).applyOperationReceipt(receipt);
      if (!mounted) return;
      ref.invalidate(mobileOperationsProvider);
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
    if (_lines.isEmpty || _storageId == null) return;
    await ref
        .read(draftStoreProvider)
        .save(
          type: 'usage',
          title:
              'Usage · ${_number(_lines.fold<double>(0, (sum, line) => sum + line.baseQuantity))} base units',
          payload: {
            'schema_version': 3,
            'storage_id': _storageId,
            'reason': _defaultReason,
            'default_reason': _defaultReason,
            'default_custom_reason': _defaultCustomReason.text.trim(),
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
                  'reason': line.reasonCode,
                  'custom_reason': line.customReason,
                },
            ],
          },
        );
    if (!mounted) return;
    _message('Draft saved on this device. Stock was not posted.');
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
    final reasons = data?.usageReasons ?? UsageReason.defaults;
    final defaultDefinition = _reason(reasons, _defaultReason);
    final total = _lines.fold<double>(
      0,
      (sum, line) => sum + line.baseQuantity,
    );
    return KonaPage(
      eyebrow: 'Review before posting',
      title: 'Usage cart',
      description:
          'Repeated scans increment the matching item. Nothing posts until you review and submit.',
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
              onPressed: _submitting || _lines.isEmpty || data == null
                  ? null
                  : () => _submit(data),
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
          onChanged: _changeStorage,
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
                const EmptyState(
                  key: ValueKey('usage-cart-empty'),
                  icon: Icons.inventory_2_outlined,
                  title: 'Cart is empty',
                  message: 'Scan or add an item. No demo stock is inserted.',
                )
              else
                ..._lines.asMap().entries.map(
                  (entry) => _CartLineEditor(
                    line: entry.value,
                    reasons: reasons,
                    defaultReason: _defaultReason,
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
                title: 'Default usage reason',
              ),
              const SizedBox(height: 6),
              const Text(
                'This reason applies to every item unless you override it inside an item row.',
                style: TextStyle(color: KonaColors.muted),
              ),
              const SizedBox(height: 13),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: reasons
                    .map(
                      (reason) => ChoiceChip(
                        key: ValueKey('usage-default-reason-${reason.code}'),
                        label: Text(reason.label),
                        selected: _defaultReason == reason.code,
                        onSelected: (_) => setState(() {
                          _defaultReason = reason.code;
                          if (!reason.requiresCustomText) {
                            _defaultCustomReason.clear();
                          }
                        }),
                      ),
                    )
                    .toList(),
              ),
              if (defaultDefinition?.requiresCustomText == true) ...[
                const SizedBox(height: 13),
                TextField(
                  controller: _defaultCustomReason,
                  decoration: const InputDecoration(
                    labelText: 'Describe Other',
                    prefixIcon: Icon(Icons.edit_note_outlined),
                  ),
                ),
              ],
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
                icon: Icon(
                  _proof == null
                      ? Icons.camera_alt_outlined
                      : Icons.check_circle_outline,
                ),
                label: Text(
                  _proof == null
                      ? (data?.requireUsageProof == true ||
                                _lines.any(
                                  (line) => line.item.requiresUsageProof,
                                ))
                            ? 'Add proof image · required'
                            : 'Add proof image · optional'
                      : 'Proof attached',
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  String _number(double value) => value == value.roundToDouble()
      ? value.toInt().toString()
      : value.toStringAsFixed(2);
}

class _CartLineEditor extends StatelessWidget {
  const _CartLineEditor({
    required this.line,
    required this.reasons,
    required this.defaultReason,
    required this.onChanged,
    required this.onRemove,
  });

  static const _useDefault = '__default__';

  final CartLine line;
  final List<UsageReason> reasons;
  final String defaultReason;
  final ValueChanged<CartLine> onChanged;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final packages = <_PackageChoice>[
      _PackageChoice(
        key: 'base',
        label: line.item.canonicalUnit,
        multiplier: 1,
      ),
      ...line.item.packagePresets.map(
        (preset) => _PackageChoice(
          key: 'preset-${preset.id}',
          presetId: preset.id,
          label: preset.label,
          multiplier: preset.piecesPerUnit,
        ),
      ),
    ];
    final selectedPackage = packages.firstWhere(
      (choice) => choice.presetId == line.packagePresetId,
      orElse: () => packages.first,
    );
    final effectiveReasonCode = line.reasonCode ?? defaultReason;
    final effectiveReason = reasons.firstWhere(
      (reason) => reason.code == effectiveReasonCode,
      orElse: () => reasons.first,
    );

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
                    '${line.item.id}-${selectedPackage.key}-${packages.length}',
                  ),
                  initialValue: selectedPackage.key,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Unit / package',
                  ),
                  items: packages
                      .map(
                        (choice) => DropdownMenuItem(
                          value: choice.key,
                          child: Text(
                            '${choice.label} · ×${_format(choice.multiplier)}',
                          ),
                        ),
                      )
                      .toList(),
                  onChanged: (value) {
                    final choice = packages.firstWhere(
                      (entry) => entry.key == value,
                      orElse: () => packages.first,
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
                  initialValue: _format(line.quantity),
                  onTap: selectAllNumericTextOnTap,
                  keyboardType: const TextInputType.numberWithOptions(
                    decimal: true,
                  ),
                  decoration: const InputDecoration(labelText: 'Quantity'),
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
            const SizedBox(height: 10),
            DropdownButtonFormField<String>(
              key: ValueKey('${line.item.id}-${line.reasonCode}'),
              initialValue: line.reasonCode ?? _useDefault,
              isExpanded: true,
              decoration: const InputDecoration(
                labelText: 'Reason override',
                prefixIcon: Icon(Icons.rule_outlined),
              ),
              items: [
                DropdownMenuItem(
                  value: _useDefault,
                  child: Text(
                    'Use cart default · ${_labelFor(reasons, defaultReason)}',
                  ),
                ),
                ...reasons.map(
                  (reason) => DropdownMenuItem(
                    value: reason.code,
                    child: Text(reason.label),
                  ),
                ),
              ],
              onChanged: (value) {
                if (value == _useDefault) {
                  onChanged(
                    line.copyWith(clearReason: true, clearCustomReason: true),
                  );
                  return;
                }
                onChanged(
                  line.copyWith(
                    reasonCode: value,
                    clearCustomReason: value != 'other',
                  ),
                );
              },
            ),
            if (line.reasonCode != null &&
                effectiveReason.requiresCustomText) ...[
              const SizedBox(height: 10),
              TextFormField(
                key: ValueKey('${line.item.id}-custom-${line.reasonCode}'),
                initialValue: line.customReason,
                decoration: const InputDecoration(
                  labelText: 'Describe Other for this item',
                  prefixIcon: Icon(Icons.edit_note_outlined),
                ),
                onChanged: (value) =>
                    onChanged(line.copyWith(customReason: value)),
              ),
            ],
            const SizedBox(height: 7),
            Align(
              alignment: Alignment.centerRight,
              child: Text(
                '${_format(line.quantity)} × ${selectedPackage.label} = '
                '${_format(line.baseQuantity)} ${line.item.canonicalUnit}',
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
  }

  static String _labelFor(List<UsageReason> reasons, String code) {
    for (final reason in reasons) {
      if (reason.code == code) return reason.label;
    }
    return code;
  }

  static String _format(double value) => value == value.roundToDouble()
      ? value.toInt().toString()
      : value.toStringAsFixed(2);
}

class _PackageChoice {
  const _PackageChoice({
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
