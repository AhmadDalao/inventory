import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/data/providers.dart';
import '../../core/models/inventory_models.dart';
import '../../core/theme/kona_theme.dart';
import '../../core/widgets/item_widgets.dart';
import '../../core/widgets/kona_page.dart';
import '../../core/widgets/status_widgets.dart';

class CreateHandoverScreen extends ConsumerStatefulWidget {
  const CreateHandoverScreen({super.key, this.purpose});

  final String? purpose;

  @override
  ConsumerState<CreateHandoverScreen> createState() =>
      _CreateHandoverScreenState();
}

class _CreateHandoverScreenState extends ConsumerState<CreateHandoverScreen> {
  final List<CartLine> _lines = [];
  late String _purpose;
  int? _sourceStorageId;
  int? _destinationStorageId;
  int? _recipientUserId;
  bool _submitting = false;

  @override
  void initState() {
    super.initState();
    _purpose = switch (widget.purpose) {
      'transfer' || 'storage_transfer' => 'storage_transfer',
      'custody' || 'staff_custody' => 'staff_custody',
      _ => 'temporary_use',
    };
  }

  Future<void> _pickItem() async {
    final data = ref.read(bootstrapProvider).valueOrNull;
    if (data == null || _sourceStorageId == null) return;
    final selected = await showModalBottomSheet<InventoryItem>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _CatalogPicker(
        items: data.items
            .where((item) => item.storageId == _sourceStorageId)
            .toList(),
      ),
    );
    if (selected != null) _addItem(selected);
  }

  Future<void> _scan() async {
    if (_sourceStorageId == null) return;
    final code = await context.push<String>('/scanner/item');
    if (code == null) return;
    final items = await ref
        .read(inventoryRepositoryProvider)
        .searchItems(code, storageId: _sourceStorageId);
    if (items.isNotEmpty) _addItem(items.first);
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

  Future<void> _saveDraft() async {
    final access = ref.read(bootstrapProvider).valueOrNull;
    if (access == null || !access.canCreateHandoverPurpose(_purpose)) {
      _accessChanged();
      return;
    }
    if (_sourceStorageId == null || _lines.isEmpty) return;
    await ref
        .read(draftStoreProvider)
        .save(
          type: 'handover',
          title: '${_purposeLabel(_purpose)} draft',
          payload: {
            'purpose': _purpose,
            'source_storage_id': _sourceStorageId,
            'destination_storage_id': _destinationStorageId,
            'recipient_user_id': _recipientUserId,
            'lines': [
              for (final line in _lines)
                {
                  'item_id': line.item.id,
                  'quantity': line.pieceQuantity,
                  'expected_balance':
                      line.expectedBalance ?? line.item.quantity,
                },
            ],
          },
        );
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Draft saved on this device.')),
    );
  }

  Future<void> _submit() async {
    final access = ref.read(bootstrapProvider).valueOrNull;
    if (access == null || !access.canCreateHandoverPurpose(_purpose)) {
      _accessChanged();
      return;
    }
    if (!_valid) return;
    setState(() => _submitting = true);
    try {
      final receipt = await ref
          .read(inventoryRepositoryProvider)
          .createHandover(
            purpose: _purpose,
            sourceStorageId: _sourceStorageId!,
            destinationStorageId: _purpose == 'storage_transfer'
                ? _destinationStorageId
                : null,
            recipientUserId: _purpose == 'storage_transfer'
                ? null
                : _recipientUserId,
            lines: _lines,
          );
      ref.invalidate(handoversProvider);
      ref.invalidate(bootstrapProvider);
      if (!mounted) return;
      await showDialog<void>(
        context: context,
        builder: (_) => AlertDialog(
          icon: const Icon(
            Icons.check_circle,
            color: KonaColors.success,
            size: 42,
          ),
          title: const Text('Handover created'),
          content: Text(
            '${receipt.reference}\nNothing else posts until the accountable workflow continues.',
          ),
          actions: [
            FilledButton(
              onPressed: () => context.pop(),
              child: const Text('Close'),
            ),
          ],
        ),
      );
      if (mounted) context.go('/handovers');
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  bool get _valid =>
      _sourceStorageId != null &&
      _lines.isNotEmpty &&
      (_purpose == 'storage_transfer'
          ? _destinationStorageId != null &&
                _destinationStorageId != _sourceStorageId
          : _recipientUserId != null);

  void _accessChanged() {
    ref.invalidate(bootstrapProvider);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('Your access changed. Refreshing available actions.'),
      ),
    );
  }

  List<ButtonSegment<String>> _purposeSegments(MobileBootstrap access) => [
    if (access.canCreateTemporaryHandover)
      const ButtonSegment(
        value: 'temporary_use',
        label: Text('Staff use'),
        icon: Icon(Icons.person_outline),
      ),
    if (access.canCreateTransfer)
      const ButtonSegment(
        value: 'storage_transfer',
        label: Text('Storage'),
        icon: Icon(Icons.warehouse_outlined),
      ),
    if (access.canCreateCustody)
      const ButtonSegment(
        value: 'staff_custody',
        label: Text('Custody'),
        icon: Icon(Icons.assignment_ind_outlined),
      ),
  ];

  @override
  Widget build(BuildContext context) {
    final data = ref.watch(bootstrapProvider).valueOrNull;
    if (data == null || !data.canCreateAnyHandover) {
      return const KonaPage(
        eyebrow: 'Accountable issue',
        title: 'New handover',
        children: [
          AccessDeniedState(
            message: 'Creating handovers is not enabled for your account.',
          ),
        ],
      );
    }
    final segments = _purposeSegments(data);
    if (!data.canCreateHandoverPurpose(_purpose)) {
      final nextPurpose = segments.first.value;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted || _purpose == nextPurpose) return;
        setState(() {
          _purpose = nextPurpose;
          _destinationStorageId = null;
          _recipientUserId = null;
          _lines.clear();
        });
      });
    }
    _sourceStorageId ??= data.defaultStorage?.id;
    final sourceItems = data.items
        .where((item) => item.storageId == _sourceStorageId)
        .toList();
    return KonaPage(
      eyebrow: 'Accountable issue',
      title: 'New handover',
      description:
          'Choose why stock is leaving, build a review cart, then let the server reserve it safely.',
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
              onPressed: !_valid || _submitting ? null : _submit,
              icon: const Icon(Icons.arrow_forward),
              label: Text(_submitting ? 'Creating' : 'Create handover'),
            ),
          ),
        ],
      ),
      children: [
        KonaSectionCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SectionHeading(
                eyebrow: 'Purpose',
                title: 'Where is this going?',
              ),
              const SizedBox(height: 12),
              SegmentedButton<String>(
                segments: segments,
                selected: {_purpose},
                showSelectedIcon: false,
                onSelectionChanged: (value) => setState(() {
                  _purpose = value.first;
                  _destinationStorageId = null;
                  _recipientUserId = null;
                }),
              ),
              const SizedBox(height: 14),
              DropdownButtonFormField<int>(
                initialValue: _sourceStorageId,
                decoration: const InputDecoration(
                  labelText: 'Source storage',
                  prefixIcon: Icon(Icons.outbox_outlined),
                ),
                items: data.storages
                    .map(
                      (storage) => DropdownMenuItem(
                        value: storage.id,
                        child: Text(storage.name),
                      ),
                    )
                    .toList(),
                onChanged: (value) => setState(() {
                  _sourceStorageId = value;
                  _lines.clear();
                  if (_destinationStorageId == value) {
                    _destinationStorageId = null;
                  }
                }),
              ),
              const SizedBox(height: 12),
              if (_purpose == 'storage_transfer')
                DropdownButtonFormField<int>(
                  initialValue: _destinationStorageId,
                  decoration: const InputDecoration(
                    labelText: 'Destination storage',
                    prefixIcon: Icon(Icons.move_to_inbox_outlined),
                  ),
                  items: data.storages
                      .where((storage) => storage.id != _sourceStorageId)
                      .map(
                        (storage) => DropdownMenuItem(
                          value: storage.id,
                          child: Text(storage.name),
                        ),
                      )
                      .toList(),
                  onChanged: (value) =>
                      setState(() => _destinationStorageId = value),
                )
              else
                DropdownButtonFormField<int>(
                  initialValue: _recipientUserId,
                  decoration: InputDecoration(
                    labelText: _purpose == 'staff_custody'
                        ? 'Employee holding the items'
                        : 'Staff recipient',
                    prefixIcon: const Icon(Icons.badge_outlined),
                  ),
                  items: data.recipients
                      .map(
                        (recipient) => DropdownMenuItem(
                          value: recipient.id,
                          child: Text(
                            '${recipient.name}${recipient.position == null ? '' : ' · ${recipient.position}'}',
                          ),
                        ),
                      )
                      .toList(),
                  onChanged: (value) =>
                      setState(() => _recipientUserId = value),
                ),
            ],
          ),
        ),
        KonaSectionCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SectionHeading(
                eyebrow: 'Review cart',
                title: '${_lines.length} item${_lines.length == 1 ? '' : 's'}',
                trailing: TextButton.icon(
                  onPressed: sourceItems.isEmpty ? null : _pickItem,
                  icon: const Icon(Icons.add),
                  label: const Text('Add'),
                ),
              ),
              const SizedBox(height: 12),
              if (_sourceStorageId == null)
                const Text('Choose a source storage first.')
              else if (_lines.isEmpty)
                OutlinedButton.icon(
                  onPressed: sourceItems.isEmpty ? null : _pickItem,
                  icon: const Icon(Icons.search),
                  label: const Text('Search source items'),
                )
              else
                ..._lines.asMap().entries.map(
                  (entry) => Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: _HandoverLineEditor(
                      line: entry.value,
                      onChanged: (line) =>
                          setState(() => _lines[entry.key] = line),
                      onRemove: () =>
                          setState(() => _lines.removeAt(entry.key)),
                    ),
                  ),
                ),
            ],
          ),
        ),
        KonaSectionCard(
          color: const Color(0xFFFFF4CF),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.shield_outlined, color: KonaColors.goldDark),
              const SizedBox(width: 11),
              Expanded(
                child: Text(
                  _purpose == 'storage_transfer'
                      ? 'Destination stock changes only after receipt confirmation.'
                      : 'Issued stock stays accountable through receipt and return.',
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  static String _purposeLabel(String purpose) => switch (purpose) {
    'storage_transfer' => 'Storage transfer',
    'staff_custody' => 'Long-term custody',
    _ => 'Staff handover',
  };
}

class _HandoverLineEditor extends StatelessWidget {
  const _HandoverLineEditor({
    required this.line,
    required this.onChanged,
    required this.onRemove,
  });

  final CartLine line;
  final ValueChanged<CartLine> onChanged;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) => Container(
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
          trailing: QuantityBadge(
            quantity: line.item.quantity,
            unit: line.item.unit,
          ),
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: TextFormField(
                initialValue: _number(line.quantity),
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: const InputDecoration(
                  labelText: 'Quantity to issue',
                  prefixIcon: Icon(Icons.numbers),
                ),
                onChanged: (value) => onChanged(
                  line.copyWith(quantity: double.tryParse(value) ?? 0),
                ),
              ),
            ),
            const SizedBox(width: 9),
            IconButton.filledTonal(
              onPressed: onRemove,
              icon: const Icon(Icons.delete_outline, color: KonaColors.danger),
            ),
          ],
        ),
      ],
    ),
  );

  static String _number(double value) => value == value.roundToDouble()
      ? value.toInt().toString()
      : value.toStringAsFixed(2);
}

class _CatalogPicker extends StatefulWidget {
  const _CatalogPicker({required this.items});

  final List<InventoryItem> items;

  @override
  State<_CatalogPicker> createState() => _CatalogPickerState();
}

class _CatalogPickerState extends State<_CatalogPicker> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final normalized = _query.toLowerCase();
    final items = widget.items
        .where(
          (item) => '${item.name} ${item.sku} ${item.barcode ?? ''}'
              .toLowerCase()
              .contains(normalized),
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
          const SectionHeading(eyebrow: 'Source catalog', title: 'Add an item'),
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
