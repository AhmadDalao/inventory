import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/api/api_client.dart';
import '../../core/data/providers.dart';
import '../../core/models/inventory_models.dart';
import '../../core/theme/kona_theme.dart';
import '../../core/widgets/kona_page.dart';
import '../../core/widgets/numeric_input.dart';
import '../../core/widgets/status_widgets.dart';

class CustodyReturnScreen extends ConsumerStatefulWidget {
  const CustodyReturnScreen({super.key, required this.handoverId});

  final int handoverId;

  @override
  ConsumerState<CustodyReturnScreen> createState() =>
      _CustodyReturnScreenState();
}

class _CustodyReturnScreenState extends ConsumerState<CustodyReturnScreen> {
  final Map<int, Map<String, TextEditingController>> _controllers = {};
  final Map<int, XFile> _proofs = {};
  final _notes = TextEditingController();
  bool _seeded = false;
  bool _submitting = false;

  @override
  void dispose() {
    for (final group in _controllers.values) {
      for (final controller in group.values) {
        controller.dispose();
      }
    }
    _notes.dispose();
    super.dispose();
  }

  void _seed(HandoverDetail detail) {
    if (_seeded) return;
    for (final line in detail.lines) {
      _controllers[line.id] = {
        'serviceable': TextEditingController(text: '0'),
        'damaged': TextEditingController(text: '0'),
        'consumed': TextEditingController(text: '0'),
        'lost': TextEditingController(text: '0'),
        'notes': TextEditingController(),
      };
    }
    _seeded = true;
  }

  double _value(int lineId, String key) =>
      double.tryParse(_controllers[lineId]?[key]?.text ?? '') ?? 0;

  String? _validationError(HandoverDetail detail) {
    var submittedTotal = 0.0;

    for (final line in detail.lines) {
      final serviceable = _value(line.id, 'serviceable');
      final damaged = _value(line.id, 'damaged');
      final consumed = _value(line.id, 'consumed');
      final lost = _value(line.id, 'lost');
      final lineTotal = serviceable + damaged + consumed + lost;
      final lineNotes = _controllers[line.id]?['notes']?.text.trim() ?? '';

      if ([serviceable, damaged, consumed, lost].any((value) => value < 0)) {
        return '${line.name}: quantities cannot be negative.';
      }
      if (lineTotal > line.quantityHeld + 0.009) {
        return '${line.name}: return outcomes exceed the quantity still held.';
      }
      if (damaged > 0 && !_proofs.containsKey(line.id)) {
        return '${line.name}: add a proof image for damaged stock.';
      }
      if (lost > 0 && lineNotes.isEmpty && _notes.text.trim().isEmpty) {
        return '${line.name}: explain the lost or missing quantity.';
      }
      submittedTotal += lineTotal;
    }

    if (submittedTotal <= 0.009) {
      return 'Enter at least one serviceable, damaged, consumed, or lost quantity.';
    }
    return null;
  }

  Future<void> _submit(HandoverDetail detail) async {
    if (!detail.task.can('return_custody')) {
      ref.invalidate(handoverDetailProvider(widget.handoverId));
      ref.invalidate(bootstrapProvider);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('This custody return is no longer available.'),
          ),
        );
      }
      return;
    }
    final validationError = _validationError(detail);
    if (validationError != null) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(validationError)));
      return;
    }
    final lines = [
      for (final line in detail.lines)
        CustodyReturnLine(
          handoverLineId: line.id,
          serviceable: _value(line.id, 'serviceable'),
          damaged: _value(line.id, 'damaged'),
          consumed: _value(line.id, 'consumed'),
          lost: _value(line.id, 'lost'),
          notes: _controllers[line.id]?['notes']?.text.trim(),
        ),
    ].where((line) => line.total > 0).toList();
    setState(() => _submitting = true);
    try {
      final receipt = await ref
          .read(inventoryRepositoryProvider)
          .submitCustodyReturn(
            handoverId: widget.handoverId,
            lines: lines,
            notes: _notes.text.trim(),
            damageProofPaths: {
              for (final entry in _proofs.entries) entry.key: entry.value.path,
            },
          );
      await ref.read(bootstrapProvider.notifier).applyOperationReceipt(receipt);
      if (!mounted) return;
      ref.invalidate(handoverDetailProvider(widget.handoverId));
      ref.invalidate(handoversProvider);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            receipt.message ?? 'Custody return submitted for review.',
          ),
        ),
      );
      if (context.canPop()) {
        context.pop(true);
      } else {
        context.go('/handovers/${widget.handoverId}');
      }
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(handoverDetailProvider(widget.handoverId));
    return detail.when(
      loading: () =>
          const Scaffold(body: Center(child: CircularProgressIndicator())),
      error: (error, _) =>
          Scaffold(body: Center(child: Text(apiErrorMessage(error)))),
      data: (data) {
        if (!data.task.can('return_custody')) {
          return const KonaPage(
            eyebrow: 'Long-term custody',
            title: 'Return held items',
            children: [
              AccessDeniedState(
                message: 'You cannot return items from this custody handover.',
              ),
            ],
          );
        }
        _seed(data);
        return KonaPage(
          eyebrow: 'Long-term custody',
          title: 'Return held items',
          description:
              'Classify each returned quantity. Damaged items need a photo; lost items need an explanation.',
          bottomAction: ElevatedButton.icon(
            onPressed: _submitting ? null : () => _submit(data),
            icon: const Icon(Icons.assignment_return_outlined),
            label: Text(_submitting ? 'Validating' : 'Submit custody return'),
          ),
          children: [
            ...data.lines.map(
              (line) => _CustodyLineCard(
                line: line,
                controllers: _controllers[line.id]!,
                proof: _proofs[line.id],
                onProof: () async {
                  final proof = await ImagePicker().pickImage(
                    source: ImageSource.camera,
                    imageQuality: 82,
                  );
                  if (proof != null && mounted) {
                    setState(() => _proofs[line.id] = proof);
                  }
                },
              ),
            ),
            KonaSectionCard(
              child: TextField(
                controller: _notes,
                maxLines: 3,
                decoration: const InputDecoration(
                  labelText: 'Return notes (optional)',
                  prefixIcon: Icon(Icons.notes_outlined),
                ),
              ),
            ),
          ],
        );
      },
    );
  }
}

class _CustodyLineCard extends StatelessWidget {
  const _CustodyLineCard({
    required this.line,
    required this.controllers,
    required this.proof,
    required this.onProof,
  });

  final HandoverLine line;
  final Map<String, TextEditingController> controllers;
  final XFile? proof;
  final VoidCallback onProof;

  @override
  Widget build(BuildContext context) => KonaSectionCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            const CircleAvatar(
              backgroundColor: KonaColors.soft,
              foregroundColor: KonaColors.ink,
              child: Icon(Icons.inventory_2_outlined),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    line.name,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  Text(
                    '${line.sku} · ${_number(line.quantityHeld)} ${line.unit} currently held',
                    style: const TextStyle(color: KonaColors.muted),
                  ),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 13),
        GridView.count(
          crossAxisCount: 2,
          childAspectRatio: 2.3,
          mainAxisSpacing: 9,
          crossAxisSpacing: 9,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          children: [
            _quantity('Serviceable', controllers['serviceable']!),
            _quantity('Damaged', controllers['damaged']!),
            _quantity('Consumed / worn', controllers['consumed']!),
            _quantity('Lost / missing', controllers['lost']!),
          ],
        ),
        const SizedBox(height: 11),
        TextField(
          controller: controllers['notes'],
          decoration: const InputDecoration(
            labelText: 'Condition / loss notes',
            prefixIcon: Icon(Icons.notes_outlined),
          ),
        ),
        const SizedBox(height: 10),
        OutlinedButton.icon(
          onPressed: onProof,
          icon: const Icon(Icons.camera_alt_outlined),
          label: Text(
            proof == null ? 'Add damage proof' : 'Damage proof attached',
          ),
        ),
      ],
    ),
  );

  Widget _quantity(String label, TextEditingController controller) => TextField(
    controller: controller,
    onTap: selectAllNumericTextOnTap,
    keyboardType: const TextInputType.numberWithOptions(decimal: true),
    decoration: InputDecoration(labelText: label),
  );
}

String _number(double value) => value == value.roundToDouble()
    ? value.toInt().toString()
    : value.toStringAsFixed(2);
