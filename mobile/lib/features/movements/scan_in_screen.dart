import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/data/providers.dart';
import '../../core/models/inventory_models.dart';
import '../../core/theme/kona_theme.dart';
import '../../core/widgets/kona_page.dart';
import '../../core/widgets/status_widgets.dart';

class ScanInScreen extends ConsumerWidget {
  const ScanInScreen({super.key});

  Future<void> _scanReference(BuildContext context, WidgetRef ref) async {
    final code = await context.push<String>('/scanner/reference');
    if (code == null || !context.mounted) return;
    final tasks =
        ref.read(handoversProvider).valueOrNull ?? const <MobileTask>[];
    MobileTask? match;
    for (final task in tasks) {
      if (task.reference.toUpperCase() == code.trim().toUpperCase()) {
        match = task;
      }
    }
    if (match == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('No assigned handover matches that reference.'),
        ),
      );
      return;
    }
    _openReceipt(context, match);
  }

  static void _openReceipt(BuildContext context, MobileTask task) {
    final review = task.status == 'receipt_review' ? '?review=1' : '';
    context.push('/handovers/${task.id}/receipt$review');
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tasks = ref.watch(handoversProvider);
    return KonaPage(
      eyebrow: 'Inbound accountability',
      title: 'Scan in',
      description:
          'Open an arriving handover, then confirm every line exactly as received.',
      trailing: IconButton.filledTonal(
        onPressed: () => _scanReference(context, ref),
        icon: const Icon(Icons.qr_code_scanner),
      ),
      children: [
        tasks.when(
          loading: () => const KonaSectionCard(
            child: Center(child: CircularProgressIndicator()),
          ),
          error: (error, _) => KonaSectionCard(child: Text(error.toString())),
          data: (items) {
            final available = items
                .where(
                  (task) =>
                      task.status == 'awaiting_receipt' ||
                      task.status == 'receipt_review',
                )
                .toList();
            return KonaSectionCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SectionHeading(
                    eyebrow: 'Waiting',
                    title: 'Choose a handover',
                  ),
                  const SizedBox(height: 12),
                  if (available.isEmpty)
                    const EmptyState(
                      icon: Icons.move_to_inbox_outlined,
                      title: 'Nothing arriving',
                      message: 'No assigned handovers are waiting for receipt.',
                    )
                  else
                    ...available.map(
                      (task) => Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: InkWell(
                          onTap: () => _openReceipt(context, task),
                          borderRadius: BorderRadius.circular(16),
                          child: Container(
                            padding: const EdgeInsets.all(14),
                            decoration: BoxDecoration(
                              color: KonaColors.canvas,
                              borderRadius: BorderRadius.circular(16),
                              border: Border.all(color: KonaColors.line),
                            ),
                            child: Row(
                              children: [
                                CircleAvatar(
                                  backgroundColor:
                                      task.status == 'receipt_review'
                                      ? const Color(0xFFFFE5D8)
                                      : KonaColors.soft,
                                  foregroundColor: KonaColors.ink,
                                  child: Icon(
                                    task.purpose == 'storage_transfer'
                                        ? Icons.warehouse_outlined
                                        : Icons.inventory_2_outlined,
                                  ),
                                ),
                                const SizedBox(width: 12),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        task.reference,
                                        style: const TextStyle(
                                          fontWeight: FontWeight.w700,
                                        ),
                                      ),
                                      Text(
                                        '${task.source ?? 'Source'} · ${_number(task.quantity)} units · ${task.itemCount} lines',
                                        style: const TextStyle(
                                          color: KonaColors.muted,
                                          fontSize: 12,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                                StatusPill(
                                  label: task.status == 'receipt_review'
                                      ? 'Review'
                                      : 'Receive',
                                  tone: StatusTone.warning,
                                ),
                                const SizedBox(width: 4),
                                const Icon(Icons.chevron_right),
                              ],
                            ),
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            );
          },
        ),
        const KonaSectionCard(
          color: Color(0xFFFFF4CF),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.shield_outlined, color: KonaColors.goldDark),
              SizedBox(width: 10),
              Expanded(
                child: Text(
                  'Exact, short, and excess receipts are valid reports. Differences are reviewed by the issuer before stock changes.',
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

String _number(double value) => value == value.roundToDouble()
    ? value.toInt().toString()
    : value.toStringAsFixed(2);
