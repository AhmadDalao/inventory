import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/data/providers.dart';
import '../../core/models/inventory_models.dart';
import '../../core/theme/kona_theme.dart';
import '../../core/widgets/kona_page.dart';

class HandoverReceiptScreen extends ConsumerStatefulWidget {
  const HandoverReceiptScreen({
    super.key,
    required this.handoverId,
    this.issuerReview = false,
  });

  final int handoverId;
  final bool issuerReview;

  @override
  ConsumerState<HandoverReceiptScreen> createState() =>
      _HandoverReceiptScreenState();
}

class _HandoverReceiptScreenState extends ConsumerState<HandoverReceiptScreen> {
  final Map<int, TextEditingController> _quantities = {};
  final _notes = TextEditingController();
  bool _submitting = false;

  @override
  void dispose() {
    for (final controller in _quantities.values) {
      controller.dispose();
    }
    _notes.dispose();
    super.dispose();
  }

  void _seed(HandoverDetail detail) {
    for (final line in detail.lines) {
      _quantities.putIfAbsent(
        line.id,
        () => TextEditingController(
          text: _number(
            line.quantityReceived > 0
                ? line.quantityReceived
                : line.quantityIssued,
          ),
        ),
      );
    }
  }

  Future<void> _submit(HandoverDetail detail) async {
    final quantities = {
      for (final line in detail.lines)
        line.id: double.tryParse(_quantities[line.id]?.text ?? '') ?? 0,
    };
    setState(() => _submitting = true);
    try {
      final receipt = widget.issuerReview
          ? await ref
                .read(inventoryRepositoryProvider)
                .confirmReceiptReview(
                  widget.handoverId,
                  quantities,
                  notes: _notes.text.trim(),
                )
          : await ref
                .read(inventoryRepositoryProvider)
                .confirmReceipt(widget.handoverId, quantities);
      ref.invalidate(handoverDetailProvider(widget.handoverId));
      ref.invalidate(handoversProvider);
      ref.invalidate(bootstrapProvider);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(receipt.message ?? 'Receipt submitted.')),
      );
      context.go('/handovers/${widget.handoverId}');
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
          Scaffold(body: Center(child: Text(error.toString()))),
      data: (data) {
        _seed(data);
        return KonaPage(
          eyebrow: widget.issuerReview ? 'Issuer decision' : 'Recipient check',
          title: widget.issuerReview
              ? 'Review receipt difference'
              : 'Confirm actual receipt',
          description: widget.issuerReview
              ? 'Confirm the receiver-reported quantities. Excess stock must exist in the source.'
              : 'Enter exactly what arrived. Short and excess quantities are valid reports and go to issuer review.',
          bottomAction: ElevatedButton.icon(
            onPressed: _submitting ? null : () => _submit(data),
            icon: const Icon(Icons.check),
            label: Text(
              _submitting
                  ? 'Validating'
                  : widget.issuerReview
                  ? 'Confirm adjusted receipt'
                  : 'Submit actual receipt',
            ),
          ),
          children: [
            KonaSectionCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SectionHeading(
                    eyebrow: 'Line by line',
                    title: 'Actual quantities',
                  ),
                  const SizedBox(height: 12),
                  ...data.lines.map(
                    (line) => Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: _ReceiptLine(
                        line: line,
                        controller: _quantities[line.id]!,
                      ),
                    ),
                  ),
                  if (widget.issuerReview) ...[
                    TextField(
                      controller: _notes,
                      maxLines: 3,
                      decoration: const InputDecoration(
                        labelText: 'Review notes (optional)',
                        prefixIcon: Icon(Icons.notes_outlined),
                      ),
                    ),
                  ],
                ],
              ),
            ),
            const KonaSectionCard(
              color: Color(0xFFFFF4CF),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.info_outline, color: KonaColors.goldDark),
                  SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'Exact receipt continues immediately. Any difference is held for issuer confirmation; the app never invents stock.',
                    ),
                  ),
                ],
              ),
            ),
          ],
        );
      },
    );
  }
}

class _ReceiptLine extends StatelessWidget {
  const _ReceiptLine({required this.line, required this.controller});

  final HandoverLine line;
  final TextEditingController controller;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(13),
    decoration: BoxDecoration(
      color: KonaColors.canvas,
      borderRadius: BorderRadius.circular(18),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Container(
          width: 50,
          height: 50,
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
                '${line.sku} · planned ${_number(line.quantityIssued)} ${line.unit}',
                style: const TextStyle(color: KonaColors.muted, fontSize: 12),
              ),
            ],
          ),
        ),
        const SizedBox(width: 10),
        SizedBox(
          width: 112,
          child: TextField(
            controller: controller,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(
              labelText: 'Received',
              suffixText: line.unit,
            ),
          ),
        ),
      ],
    ),
  );
}

String _number(double value) => value == value.roundToDouble()
    ? value.toInt().toString()
    : value.toStringAsFixed(2);
