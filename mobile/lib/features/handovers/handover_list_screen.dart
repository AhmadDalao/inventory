import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/data/providers.dart';
import '../../core/models/inventory_models.dart';
import '../../core/theme/kona_theme.dart';
import '../../core/widgets/kona_page.dart';
import '../../core/widgets/status_widgets.dart';

class HandoverListScreen extends ConsumerWidget {
  const HandoverListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final handovers = ref.watch(handoversProvider);
    return KonaPage(
      eyebrow: 'Accountable movement',
      title: 'My handovers',
      description:
          'Receive items, report temporary usage, return custody stock, or track transfers.',
      trailing: IconButton.filledTonal(
        onPressed: () => context.push('/create-handover'),
        icon: const Icon(Icons.add),
      ),
      children: [
        handovers.when(
          loading: () => const KonaSectionCard(
            child: Center(child: CircularProgressIndicator()),
          ),
          error: (error, _) =>
              KonaSectionCard(child: Text(apiErrorMessage(error))),
          data: (items) => KonaSectionCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SectionHeading(
                  eyebrow: 'Assigned and issued',
                  title:
                      '${items.length} record${items.length == 1 ? '' : 's'}',
                  trailing: IconButton(
                    tooltip: 'Refresh',
                    onPressed: () => ref.invalidate(handoversProvider),
                    icon: const Icon(Icons.refresh),
                  ),
                ),
                const SizedBox(height: 12),
                if (items.isEmpty)
                  const EmptyState(
                    icon: Icons.swap_horiz,
                    title: 'No handovers yet',
                    message: 'New receipts and assignments will appear here.',
                  )
                else
                  ...items.map(
                    (task) => Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: _HandoverTile(task: task),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _HandoverTile extends StatelessWidget {
  const _HandoverTile({required this.task});

  final MobileTask task;

  @override
  Widget build(BuildContext context) => Material(
    color: task.requiresAction ? const Color(0xFFFFF7DE) : KonaColors.surface,
    borderRadius: BorderRadius.circular(18),
    child: InkWell(
      onTap: () => context.push('/handovers/${task.id}'),
      borderRadius: BorderRadius.circular(18),
      child: Container(
        padding: const EdgeInsets.all(15),
        decoration: BoxDecoration(
          border: Border.all(
            color: task.requiresAction ? KonaColors.gold : KonaColors.line,
          ),
          borderRadius: BorderRadius.circular(18),
        ),
        child: Row(
          children: [
            CircleAvatar(
              backgroundColor: KonaColors.soft,
              foregroundColor: KonaColors.ink,
              child: Icon(_purposeIcon(task.purpose)),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    task.title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    task.reference,
                    style: const TextStyle(
                      color: KonaColors.muted,
                      fontSize: 12,
                    ),
                  ),
                  const SizedBox(height: 5),
                  Text(
                    '${task.source ?? 'Source'}${task.destination == null ? '' : ' → ${task.destination}'} · ${_number(task.quantity)} units · ${task.itemCount} lines',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: KonaColors.muted,
                      fontSize: 12,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            StatusPill(
              label: _statusLabel(task.status),
              tone: _statusTone(task.status),
            ),
          ],
        ),
      ),
    ),
  );

  static IconData _purposeIcon(String purpose) => switch (purpose) {
    'storage_transfer' => Icons.warehouse_outlined,
    'staff_custody' => Icons.assignment_ind_outlined,
    _ => Icons.person_outline,
  };

  static String _statusLabel(String status) => status
      .replaceAll('_', ' ')
      .split(' ')
      .map(
        (word) => word.isEmpty
            ? word
            : '${word[0].toUpperCase()}${word.substring(1)}',
      )
      .join(' ');

  static StatusTone _statusTone(String status) => switch (status) {
    'closed' || 'delivered' => StatusTone.success,
    'cancelled' || 'rejected' => StatusTone.danger,
    'awaiting_receipt' ||
    'receipt_review' ||
    'pending_approval' => StatusTone.warning,
    _ => StatusTone.neutral,
  };

  static String _number(double value) => value == value.roundToDouble()
      ? value.toInt().toString()
      : value.toStringAsFixed(2);
}
