import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/api/api_client.dart';
import '../../core/data/providers.dart';
import '../../core/logic/handover_reconciliation.dart';
import '../../core/models/inventory_models.dart';
import '../../core/theme/kona_theme.dart';
import '../../core/widgets/kona_page.dart';
import '../../core/widgets/numeric_input.dart';
import '../../core/widgets/status_widgets.dart';

const _reasonKeys = [
  'online',
  'walkin',
  'event',
  'sport',
  'damage',
  'complimentary',
  'noshow',
  'other',
];

class HandoverCloseoutScreen extends ConsumerStatefulWidget {
  const HandoverCloseoutScreen({
    super.key,
    required this.handoverId,
    this.issuerApproval = false,
  });

  final int handoverId;
  final bool issuerApproval;

  @override
  ConsumerState<HandoverCloseoutScreen> createState() =>
      _HandoverCloseoutScreenState();
}

class _HandoverCloseoutScreenState
    extends ConsumerState<HandoverCloseoutScreen> {
  final Map<int, TextEditingController> _returned = {};
  final Map<String, Map<String, TextEditingController>> _reasons = {};
  final Map<String, TextEditingController> _discrepancy = {};
  final Map<String, TextEditingController> _varianceNotes = {};
  final Map<String, String> _varianceReasons = {};
  final _notes = TextEditingController();
  XFile? _proof;
  bool _seeded = false;
  bool _submitting = false;

  @override
  void dispose() {
    for (final controller in _returned.values) {
      controller.dispose();
    }
    for (final group in _reasons.values) {
      for (final controller in group.values) {
        controller.dispose();
      }
    }
    for (final controller in [
      ..._discrepancy.values,
      ..._varianceNotes.values,
    ]) {
      controller.dispose();
    }
    _notes.dispose();
    super.dispose();
  }

  void _seed(HandoverDetail detail) {
    if (_seeded) return;
    for (final line in detail.lines) {
      final defaultReturned = line.quantityReturned > 0
          ? line.quantityReturned
          : 0;
      _returned[line.id] = TextEditingController(
        text: _number(defaultReturned.toDouble()),
      );
    }
    final units = detail.lines.map((line) => line.unit).toSet();
    for (final unit in units) {
      HandoverReconciliation? existing;
      for (final reconciliation in detail.reconciliations) {
        if (reconciliation.unit == unit) existing = reconciliation;
      }
      _reasons[unit] = {
        for (final reason in _reasonKeys)
          reason: TextEditingController(
            text: _number(existing?.reasons[reason] ?? 0),
          ),
      };
      _discrepancy[unit] = TextEditingController(
        text: existing?.discrepancyNotes ?? '',
      );
      _varianceNotes[unit] = TextEditingController(
        text: existing?.varianceNotes ?? '',
      );
      _varianceReasons[unit] = existing?.varianceReason ?? 'count_variance';
    }
    _seeded = true;
  }

  double _receivedFor(HandoverLine line) =>
      line.quantityReceived > 0 ? line.quantityReceived : line.quantityIssued;

  double _returnedFor(HandoverLine line) =>
      double.tryParse(_returned[line.id]?.text ?? '') ?? 0;

  double _usedFor(HandoverLine line) => HandoverReconciliationMath.physicalUsed(
    received: _receivedFor(line),
    returned: _returnedFor(line),
  );

  double _physicalUsed(HandoverDetail detail, String unit) => detail.lines
      .where((line) => line.unit == unit)
      .fold(0, (sum, line) => sum + _usedFor(line));

  Map<String, double> _reasonValues(String unit) => {
    for (final reason in _reasonKeys)
      reason: double.tryParse(_reasons[unit]?[reason]?.text ?? '') ?? 0,
  };

  double _operationalUsed(String unit) {
    return HandoverReconciliationMath.operationalUsed(_reasonValues(unit));
  }

  Future<void> _submit(HandoverDetail detail) async {
    final action = widget.issuerApproval
        ? 'approve_closeout'
        : 'report_closeout';
    if (!detail.task.can(action)) {
      ref.invalidate(handoverDetailProvider(widget.handoverId));
      ref.invalidate(bootstrapProvider);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('This closeout action is no longer available.'),
          ),
        );
      }
      return;
    }
    final returned = {
      for (final line in detail.lines) line.id: _returnedFor(line),
    };
    final reconciliations = {
      for (final unit in _reasons.keys) unit: _reasonValues(unit),
    };
    setState(() => _submitting = true);
    try {
      final receipt = widget.issuerApproval
          ? await ref
                .read(inventoryRepositoryProvider)
                .approveCloseout(
                  handoverId: widget.handoverId,
                  returnedQuantities: returned,
                  reconciliations: reconciliations,
                  discrepancyNotes: {
                    for (final entry in _discrepancy.entries)
                      entry.key: entry.value.text.trim(),
                  },
                  varianceReasons: _varianceReasons,
                  varianceNotes: {
                    for (final entry in _varianceNotes.entries)
                      entry.key: entry.value.text.trim(),
                  },
                  notes: _notes.text.trim(),
                )
          : await ref
                .read(inventoryRepositoryProvider)
                .submitCloseout(
                  handoverId: widget.handoverId,
                  returnedQuantities: returned,
                  reconciliations: reconciliations,
                  discrepancyNotes: {
                    for (final entry in _discrepancy.entries)
                      entry.key: entry.value.text.trim(),
                  },
                  notes: _notes.text.trim(),
                  proofPath: _proof?.path,
                );
      await ref.read(bootstrapProvider.notifier).applyOperationReceipt(receipt);
      if (!mounted) return;
      ref.invalidate(handoverDetailProvider(widget.handoverId));
      ref.invalidate(handoversProvider);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            receipt.message ??
                (widget.issuerApproval
                    ? 'Approved and closed.'
                    : 'Submitted for issuer approval.'),
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
        final action = widget.issuerApproval
            ? 'approve_closeout'
            : 'report_closeout';
        if (!data.task.can(action)) {
          return KonaPage(
            eyebrow: widget.issuerApproval
                ? 'Final stock accountability'
                : 'Returned-first reporting',
            title: widget.issuerApproval
                ? 'Issuer final review'
                : 'Usage and return',
            children: const [
              AccessDeniedState(
                message:
                    'This closeout action is not available to you or is no longer waiting.',
              ),
            ],
          );
        }
        _seed(data);
        final units = data.lines.map((line) => line.unit).toSet().toList();
        return KonaPage(
          eyebrow: widget.issuerApproval
              ? 'Final stock accountability'
              : 'Returned-first reporting',
          title: widget.issuerApproval
              ? 'Issuer final review'
              : 'Usage and return',
          description: widget.issuerApproval
              ? 'Correct returns or operational totals before approval. Approval is when stock posts.'
              : 'Enter what came back. Used quantity is calculated, then reconcile the operational totals.',
          bottomAction: ElevatedButton.icon(
            onPressed: _submitting ? null : () => _submit(data),
            icon: Icon(
              widget.issuerApproval
                  ? Icons.verified_outlined
                  : Icons.send_outlined,
            ),
            label: Text(
              _submitting
                  ? 'Validating'
                  : widget.issuerApproval
                  ? 'Approve final stock'
                  : 'Submit for issuer approval',
            ),
          ),
          children: [
            KonaSectionCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SectionHeading(
                    eyebrow: 'Physical stock',
                    title: 'Returned quantities',
                  ),
                  const SizedBox(height: 12),
                  ...data.lines.map(
                    (line) => Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: _ReturnLine(
                        line: line,
                        controller: _returned[line.id]!,
                        received: _receivedFor(line),
                        used: _usedFor(line),
                        onChanged: () => setState(() {}),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            ...units.map((unit) {
              final physical = _physicalUsed(data, unit);
              final operational = _operationalUsed(unit);
              final difference = physical - operational;
              return _ReconciliationEditor(
                unit: unit,
                physicalUsed: physical,
                operationalUsed: operational,
                difference: difference,
                reasonControllers: _reasons[unit]!,
                discrepancyController: _discrepancy[unit]!,
                issuerApproval: widget.issuerApproval,
                varianceReason: _varianceReasons[unit]!,
                varianceNotesController: _varianceNotes[unit]!,
                onVarianceReasonChanged: (value) =>
                    setState(() => _varianceReasons[unit] = value),
                onChanged: () => setState(() {}),
              );
            }),
            KonaSectionCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  TextField(
                    controller: _notes,
                    maxLines: 3,
                    decoration: InputDecoration(
                      labelText: widget.issuerApproval
                          ? 'Approval notes (optional)'
                          : 'Closeout notes (optional)',
                      prefixIcon: const Icon(Icons.notes_outlined),
                    ),
                  ),
                  if (!widget.issuerApproval) ...[
                    const SizedBox(height: 12),
                    OutlinedButton.icon(
                      onPressed: () async {
                        final proof = await ImagePicker().pickImage(
                          source: ImageSource.camera,
                          imageQuality: 82,
                        );
                        if (mounted) setState(() => _proof = proof);
                      },
                      icon: const Icon(Icons.camera_alt_outlined),
                      label: Text(
                        _proof == null
                            ? 'Add return proof image'
                            : 'Proof image attached',
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
        );
      },
    );
  }
}

class _ReturnLine extends StatelessWidget {
  const _ReturnLine({
    required this.line,
    required this.controller,
    required this.received,
    required this.used,
    required this.onChanged,
  });

  final HandoverLine line;
  final TextEditingController controller;
  final double received;
  final double used;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(13),
    decoration: BoxDecoration(
      color: KonaColors.canvas,
      borderRadius: BorderRadius.circular(18),
    ),
    child: Column(
      children: [
        Row(
          children: [
            Container(
              width: 48,
              height: 48,
              clipBehavior: Clip.antiAlias,
              decoration: BoxDecoration(
                color: KonaColors.soft,
                borderRadius: BorderRadius.circular(14),
              ),
              child: line.imageUrl == null || line.imageUrl!.isEmpty
                  ? const Icon(Icons.inventory_2_outlined)
                  : Image.network(
                      line.imageUrl!,
                      fit: BoxFit.cover,
                      errorBuilder: (_, _, _) =>
                          const Icon(Icons.inventory_2_outlined),
                    ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    line.name,
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                  Text(
                    '${line.sku} · ${line.unit}',
                    style: const TextStyle(
                      color: KonaColors.muted,
                      fontSize: 12,
                    ),
                  ),
                ],
              ),
            ),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  '${_number(received)} received',
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
                Text(
                  '${_number(used)} used',
                  style: const TextStyle(
                    color: KonaColors.goldDark,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ],
        ),
        const SizedBox(height: 11),
        TextField(
          controller: controller,
          onTap: selectAllNumericTextOnTap,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          onChanged: (_) => onChanged(),
          decoration: InputDecoration(
            labelText: 'Returned quantity',
            prefixIcon: const Icon(Icons.assignment_return_outlined),
            suffixText: line.unit,
          ),
        ),
      ],
    ),
  );
}

class _ReconciliationEditor extends StatelessWidget {
  const _ReconciliationEditor({
    required this.unit,
    required this.physicalUsed,
    required this.operationalUsed,
    required this.difference,
    required this.reasonControllers,
    required this.discrepancyController,
    required this.issuerApproval,
    required this.varianceReason,
    required this.varianceNotesController,
    required this.onVarianceReasonChanged,
    required this.onChanged,
  });

  final String unit;
  final double physicalUsed;
  final double operationalUsed;
  final double difference;
  final Map<String, TextEditingController> reasonControllers;
  final TextEditingController discrepancyController;
  final bool issuerApproval;
  final String varianceReason;
  final TextEditingController varianceNotesController;
  final ValueChanged<String> onVarianceReasonChanged;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) => KonaSectionCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SectionHeading(
          eyebrow: 'Operational reconciliation · $unit',
          title: 'How was ${_number(physicalUsed)} used?',
        ),
        const SizedBox(height: 12),
        GridView.count(
          crossAxisCount: MediaQuery.sizeOf(context).width >= 600 ? 4 : 2,
          childAspectRatio: 2.5,
          mainAxisSpacing: 9,
          crossAxisSpacing: 9,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          children: [
            for (final reason in _reasonKeys)
              TextField(
                controller: reasonControllers[reason],
                onTap: selectAllNumericTextOnTap,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                onChanged: (_) => onChanged(),
                decoration: InputDecoration(labelText: _reasonLabel(reason)),
              ),
          ],
        ),
        const SizedBox(height: 12),
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: difference == 0
                ? const Color(0xFFDDF6E9)
                : const Color(0xFFFFE2DE),
            borderRadius: BorderRadius.circular(16),
          ),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'DIFFERENCE / UNACCOUNTED',
                      style: TextStyle(
                        fontSize: 10,
                        fontWeight: FontWeight.w700,
                        letterSpacing: .8,
                      ),
                    ),
                    Text(
                      'Physical ${_number(physicalUsed)} − operational ${_number(operationalUsed)}',
                      style: const TextStyle(fontSize: 12),
                    ),
                  ],
                ),
              ),
              Text(
                _number(difference),
                style: TextStyle(
                  fontSize: 24,
                  fontWeight: FontWeight.w700,
                  color: difference == 0
                      ? KonaColors.success
                      : KonaColors.danger,
                ),
              ),
            ],
          ),
        ),
        if (difference != 0) ...[
          const SizedBox(height: 12),
          TextField(
            controller: discrepancyController,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'Receiver discrepancy note',
              prefixIcon: Icon(Icons.report_problem_outlined),
            ),
          ),
        ],
        if (issuerApproval && difference > 0) ...[
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            initialValue: varianceReason,
            decoration: const InputDecoration(
              labelText: 'Audited variance reason',
              prefixIcon: Icon(Icons.fact_check_outlined),
            ),
            items: const [
              DropdownMenuItem(
                value: 'count_variance',
                child: Text('Count variance'),
              ),
              DropdownMenuItem(
                value: 'unreported_usage',
                child: Text('Unreported usage'),
              ),
              DropdownMenuItem(
                value: 'lost_missing',
                child: Text('Lost / missing'),
              ),
              DropdownMenuItem(value: 'other', child: Text('Other')),
            ],
            onChanged: (value) {
              if (value != null) onVarianceReasonChanged(value);
            },
          ),
          const SizedBox(height: 12),
          TextField(
            controller: varianceNotesController,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'Variance approval note',
              prefixIcon: Icon(Icons.gavel_outlined),
            ),
          ),
        ],
      ],
    ),
  );
}

String _reasonLabel(String reason) => switch (reason) {
  'walkin' => 'Walk-in',
  'noshow' => 'No show',
  _ => '${reason[0].toUpperCase()}${reason.substring(1)}',
};
String _number(double value) => value == value.roundToDouble()
    ? value.toInt().toString()
    : value.toStringAsFixed(2);
