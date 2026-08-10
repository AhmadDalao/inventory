import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/data/providers.dart';
import '../../core/models/inventory_models.dart';
import '../../core/theme/kona_theme.dart';
import '../../core/widgets/kona_page.dart';
import '../../core/widgets/status_widgets.dart';

class HandoverDetailScreen extends ConsumerWidget {
  const HandoverDetailScreen({super.key, required this.handoverId});

  final int handoverId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(handoverDetailProvider(handoverId));
    return detail.when(
      loading: () =>
          const Scaffold(body: Center(child: CircularProgressIndicator())),
      error: (error, _) => Scaffold(
        appBar: AppBar(title: const Text('Handover')),
        body: Center(child: Text(error.toString())),
      ),
      data: (data) => KonaPage(
        eyebrow: _purposeLabel(data.task.purpose),
        title: data.task.reference,
        description:
            '${data.task.source ?? 'Source'}${data.task.destination == null ? '' : ' → ${data.task.destination}'}',
        trailing: StatusPill(
          label: _statusLabel(data.task.status),
          tone: _statusTone(data.task.status),
        ),
        children: [
          _SummaryCard(detail: data),
          _NextActionCard(
            detail: data,
            onChanged: () {
              ref.invalidate(handoverDetailProvider(handoverId));
              ref.invalidate(handoversProvider);
              ref.invalidate(bootstrapProvider);
            },
          ),
          KonaSectionCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SectionHeading(
                  eyebrow: 'Lines',
                  title:
                      '${data.lines.length} item${data.lines.length == 1 ? '' : 's'}',
                ),
                const SizedBox(height: 12),
                ...data.lines.map(
                  (line) => Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: _LineTile(line: line),
                  ),
                ),
              ],
            ),
          ),
          if (data.reconciliations.isNotEmpty)
            _ReconciliationCard(items: data.reconciliations),
        ],
      ),
    );
  }

  static String _purposeLabel(String purpose) => switch (purpose) {
    'storage_transfer' => 'Storage transfer',
    'staff_custody' => 'Long-term staff custody',
    _ => 'Temporary staff use',
  };
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({required this.detail});

  final HandoverDetail detail;

  @override
  Widget build(BuildContext context) {
    final issued = detail.lines.fold<double>(
      0,
      (sum, line) => sum + line.quantityIssued,
    );
    final received = detail.lines.fold<double>(
      0,
      (sum, line) => sum + line.quantityReceived,
    );
    final used = detail.lines.fold<double>(
      0,
      (sum, line) => sum + line.quantityUsed,
    );
    final held = detail.lines.fold<double>(
      0,
      (sum, line) => sum + line.quantityHeld,
    );
    return KonaSectionCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const CircleAvatar(
                radius: 28,
                backgroundColor: KonaColors.soft,
                foregroundColor: KonaColors.ink,
                child: Icon(Icons.person_outline),
              ),
              const SizedBox(width: 13),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      detail.recipientName ??
                          detail.task.destination ??
                          'Recipient',
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    Text(
                      'Issued by ${detail.issuerName ?? 'storage owner'}${detail.scheduledForDate == null ? '' : ' · ${detail.scheduledForDate}'}',
                      style: const TextStyle(color: KonaColors.muted),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 17),
          LayoutBuilder(
            builder: (context, constraints) {
              final width = (constraints.maxWidth - 9) / 2;
              return Wrap(
                spacing: 9,
                runSpacing: 9,
                children: [
                  _Metric(
                    width: width,
                    label: 'Issued',
                    value: _number(issued),
                  ),
                  _Metric(
                    width: width,
                    label: 'Received',
                    value: _number(received),
                  ),
                  _Metric(
                    width: width,
                    label: detail.task.purpose == 'staff_custody'
                        ? 'Held'
                        : 'Used',
                    value: _number(
                      detail.task.purpose == 'staff_custody' ? held : used,
                    ),
                  ),
                  _Metric(
                    width: width,
                    label: 'Lines',
                    value: '${detail.lines.length}',
                  ),
                ],
              );
            },
          ),
          if (detail.reviewDate != null) ...[
            const SizedBox(height: 12),
            Text(
              'Review / return date: ${detail.reviewDate}',
              style: const TextStyle(
                color: KonaColors.goldDark,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({
    required this.width,
    required this.label,
    required this.value,
  });

  final double width;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Container(
    width: width,
    padding: const EdgeInsets.all(13),
    decoration: BoxDecoration(
      color: KonaColors.canvas,
      borderRadius: BorderRadius.circular(16),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label.toUpperCase(),
          style: const TextStyle(
            color: KonaColors.muted,
            fontSize: 10,
            fontWeight: FontWeight.w700,
            letterSpacing: .8,
          ),
        ),
        const SizedBox(height: 4),
        Text(value, style: Theme.of(context).textTheme.titleLarge),
      ],
    ),
  );
}

class _NextActionCard extends ConsumerWidget {
  const _NextActionCard({required this.detail, required this.onChanged});

  final HandoverDetail detail;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final task = detail.task;
    final actions = <Widget>[];
    if (task.status == 'requested') {
      actions.addAll([
        ElevatedButton.icon(
          onPressed: () => _decide(context, ref, true),
          icon: const Icon(Icons.check),
          label: const Text('Approve request'),
        ),
        OutlinedButton.icon(
          onPressed: () => _decide(context, ref, false),
          icon: const Icon(Icons.close),
          label: const Text('Reject'),
        ),
      ]);
    } else if (task.status == 'awaiting_receipt') {
      actions.add(
        ElevatedButton.icon(
          onPressed: () => context.push('/handovers/${task.id}/receipt'),
          icon: const Icon(Icons.inventory_2_outlined),
          label: const Text('Confirm actual receipt'),
        ),
      );
    } else if (task.status == 'receipt_review') {
      actions.add(
        ElevatedButton.icon(
          onPressed: () =>
              context.push('/handovers/${task.id}/receipt?review=1'),
          icon: const Icon(Icons.fact_check_outlined),
          label: const Text('Review receipt difference'),
        ),
      );
    } else if (task.status == 'delivered' && task.purpose == 'temporary_use') {
      actions.add(
        ElevatedButton.icon(
          onPressed: () => context.push('/handovers/${task.id}/closeout'),
          icon: const Icon(Icons.assignment_return_outlined),
          label: const Text('Report return and usage'),
        ),
      );
    } else if (task.status == 'pending_approval' &&
        task.purpose == 'temporary_use') {
      actions.add(
        ElevatedButton.icon(
          onPressed: () =>
              context.push('/handovers/${task.id}/closeout?approval=1'),
          icon: const Icon(Icons.verified_outlined),
          label: const Text('Final issuer review'),
        ),
      );
    } else if (task.status == 'delivered' && task.purpose == 'staff_custody') {
      actions.add(
        ElevatedButton.icon(
          onPressed: () => context.push('/handovers/${task.id}/custody-return'),
          icon: const Icon(Icons.assignment_return_outlined),
          label: const Text('Return custody items'),
        ),
      );
    }
    if (!{'closed', 'cancelled', 'rejected'}.contains(task.status)) {
      actions.add(
        OutlinedButton.icon(
          onPressed: () => _cancel(context, ref),
          icon: const Icon(Icons.cancel_outlined),
          label: const Text('Cancel / report issue'),
        ),
      );
    }
    return KonaSectionCard(
      color: const Color(0xFFFFF7DE),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SectionHeading(
            eyebrow: 'Next step',
            title: actions.isEmpty ? 'No action required' : 'Continue workflow',
          ),
          const SizedBox(height: 12),
          if (actions.isEmpty)
            const Text(
              'This record is complete or waiting on another person.',
              style: TextStyle(color: KonaColors.muted),
            )
          else
            ...actions
                .expand((action) => [action, const SizedBox(height: 9)])
                .take(actions.length * 2 - 1),
        ],
      ),
    );
  }

  Future<void> _decide(
    BuildContext context,
    WidgetRef ref,
    bool approve,
  ) async {
    final receipt = await ref
        .read(inventoryRepositoryProvider)
        .decideRequest(detail.task.id, approve: approve);
    if (!context.mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(receipt.message ?? (approve ? 'Approved.' : 'Rejected.')),
      ),
    );
    onChanged();
  }

  Future<void> _cancel(BuildContext context, WidgetRef ref) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Cancel this handover?'),
        content: const Text(
          'The server will reverse only movements that are safe to reverse and preserve the audit history.',
        ),
        actions: [
          TextButton(
            onPressed: () => context.pop(false),
            child: const Text('Keep'),
          ),
          FilledButton(
            onPressed: () => context.pop(true),
            child: const Text('Cancel handover'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    final receipt = await ref
        .read(inventoryRepositoryProvider)
        .cancelHandover(detail.task.id, notes: 'Cancelled from mobile app');
    if (!context.mounted) return;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(receipt.message ?? 'Cancelled.')));
    onChanged();
  }
}

class _LineTile extends StatelessWidget {
  const _LineTile({required this.line});

  final HandoverLine line;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: KonaColors.canvas,
      borderRadius: BorderRadius.circular(17),
    ),
    child: Row(
      children: [
        _LineThumbnail(line: line),
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
                style: const TextStyle(color: KonaColors.muted, fontSize: 12),
              ),
              const SizedBox(height: 5),
              Text(
                'Issued ${_number(line.quantityIssued)} · Received ${_number(line.quantityReceived)} · Used ${_number(line.quantityUsed)} · Returned ${_number(line.quantityReturned)}',
                style: const TextStyle(fontSize: 12),
              ),
            ],
          ),
        ),
      ],
    ),
  );
}

class _LineThumbnail extends StatelessWidget {
  const _LineThumbnail({required this.line});

  final HandoverLine line;
  static const double size = 48;

  @override
  Widget build(BuildContext context) => Container(
    width: size,
    height: size,
    clipBehavior: Clip.antiAlias,
    decoration: BoxDecoration(
      color: KonaColors.soft,
      borderRadius: BorderRadius.circular(14),
      border: Border.all(color: KonaColors.line),
    ),
    child: line.imageUrl == null || line.imageUrl!.isEmpty
        ? const Icon(Icons.inventory_2_outlined)
        : Image.network(
            line.imageUrl!,
            fit: BoxFit.cover,
            errorBuilder: (_, _, _) => const Icon(Icons.inventory_2_outlined),
          ),
  );
}

class _ReconciliationCard extends StatelessWidget {
  const _ReconciliationCard({required this.items});

  final List<HandoverReconciliation> items;

  @override
  Widget build(BuildContext context) => KonaSectionCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SectionHeading(
          eyebrow: 'Operational report',
          title: 'Reconciliation',
        ),
        const SizedBox(height: 12),
        ...items.map(
          (item) => Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: KonaColors.canvas,
                borderRadius: BorderRadius.circular(16),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    item.unit.toUpperCase(),
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    item.reasons.entries
                        .where((entry) => entry.value != 0)
                        .map(
                          (entry) =>
                              '${_reasonLabel(entry.key)} ${_number(entry.value)}',
                        )
                        .join(' · '),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    'Returned ${_number(item.returnedTotal)} · Difference ${_number(item.difference)}',
                    style: TextStyle(
                      fontWeight: FontWeight.w700,
                      color: item.difference == 0
                          ? KonaColors.success
                          : KonaColors.danger,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    ),
  );
}

String _statusLabel(String status) => status
    .replaceAll('_', ' ')
    .split(' ')
    .map(
      (word) =>
          word.isEmpty ? word : '${word[0].toUpperCase()}${word.substring(1)}',
    )
    .join(' ');
StatusTone _statusTone(String status) => switch (status) {
  'closed' || 'delivered' => StatusTone.success,
  'cancelled' || 'rejected' => StatusTone.danger,
  'awaiting_receipt' ||
  'receipt_review' ||
  'pending_approval' => StatusTone.warning,
  _ => StatusTone.neutral,
};
String _reasonLabel(String reason) => reason
    .replaceAll('_', ' ')
    .split(' ')
    .map(
      (part) =>
          part.isEmpty ? part : '${part[0].toUpperCase()}${part.substring(1)}',
    )
    .join(' ');
String _number(double value) => value == value.roundToDouble()
    ? value.toInt().toString()
    : value.toStringAsFixed(2);
