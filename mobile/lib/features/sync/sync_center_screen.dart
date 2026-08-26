import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/config/app_config.dart';
import '../../core/data/providers.dart';
import '../../core/models/inventory_models.dart';
import '../../core/theme/kona_theme.dart';
import '../../core/widgets/kona_page.dart';
import '../../core/widgets/status_widgets.dart';
import 'draft_replay.dart';

class SyncCenterScreen extends ConsumerStatefulWidget {
  const SyncCenterScreen({super.key});

  @override
  ConsumerState<SyncCenterScreen> createState() => _SyncCenterScreenState();
}

class _SyncCenterScreenState extends ConsumerState<SyncCenterScreen> {
  String? _activeId;

  Future<void> _retry(PendingDraft draft) async {
    setState(() => _activeId = draft.id);
    final store = ref.read(draftStoreProvider);
    try {
      final payload = await store.payload(draft.id);
      if (payload == null) throw StateError('Draft data is missing.');
      final bootstrap = await ref.read(inventoryRepositoryProvider).bootstrap();
      final sourceStorageId = draft.type == 'handover'
          ? (payload['source_storage_id'] as num?)?.toInt() ?? 0
          : (payload['storage_id'] as num?)?.toInt() ?? 0;
      final lines = resolveDraftCartLines(
        payload,
        bootstrap,
        sourceStorageId: sourceStorageId,
      );
      late final OperationReceipt receipt;
      if (draft.type == 'usage') {
        receipt = await ref
            .read(inventoryRepositoryProvider)
            .submitUsage(
              storageId: (payload['storage_id'] as num).toInt(),
              lines: lines,
              defaultReason:
                  payload['default_reason'] as String? ??
                  payload['reason'] as String? ??
                  'online',
              defaultCustomReason:
                  payload['default_custom_reason'] as String? ??
                  payload['custom_reason'] as String?,
              notes: payload['notes'] as String?,
              proofPath: payload['proof_path'] as String?,
              clientOperationId: draft.id,
            );
      } else if (draft.type == 'restock') {
        receipt = await ref
            .read(inventoryRepositoryProvider)
            .submitRestock(
              storageId: (payload['storage_id'] as num).toInt(),
              lines: lines,
              reference: payload['reference'] as String?,
              notes: payload['notes'] as String?,
              proofPath: payload['proof_path'] as String?,
              clientOperationId: draft.id,
            );
      } else if (draft.type == 'handover') {
        receipt = await ref
            .read(inventoryRepositoryProvider)
            .createHandover(
              purpose: payload['purpose'] as String? ?? 'temporary_use',
              sourceStorageId: (payload['source_storage_id'] as num).toInt(),
              destinationStorageId: (payload['destination_storage_id'] as num?)
                  ?.toInt(),
              recipientUserId: (payload['recipient_user_id'] as num?)?.toInt(),
              lines: lines,
              clientOperationId: draft.id,
            );
      } else {
        throw StateError('This draft type cannot be retried yet.');
      }
      await store.updateState(
        draft.id,
        'completed',
        message: 'Accepted by the server.',
      );
      await ref.read(bootstrapProvider.notifier).applyOperationReceipt(receipt);
      ref.invalidate(handoversProvider);
      ref.invalidate(mobileOperationsProvider);
    } on ApiFailure catch (error) {
      final state = error.code == 'balance_changed' ? 'conflict' : 'failed';
      await store.updateState(draft.id, state, message: error.message);
    } on DraftReplayException catch (error) {
      await store.updateState(draft.id, 'conflict', message: error.message);
    } catch (error) {
      await store.updateState(
        draft.id,
        'failed',
        message: apiErrorMessage(error),
      );
    } finally {
      if (mounted) setState(() => _activeId = null);
    }
  }

  Future<void> _refresh() async {
    ref.invalidate(bootstrapProvider);
    ref.invalidate(handoversProvider);
    ref.invalidate(mobileOperationsProvider);
    await Future.wait([
      ref.read(bootstrapProvider.future),
      ref.read(mobileOperationsProvider.future),
    ]);
  }

  @override
  Widget build(BuildContext context) {
    final drafts = ref.watch(pendingDraftsProvider);
    final operations = ref.watch(mobileOperationsProvider);
    return KonaPage(
      eyebrow: 'Offline-safe operations',
      title: 'Sync center',
      description:
          'Drafts never post stock offline. The server revalidates every retry.',
      trailing: IconButton.filledTonal(
        onPressed: _refresh,
        icon: const Icon(Icons.sync),
      ),
      children: [
        if (AppConfig.mockMode)
          const KonaSectionCard(
            color: Color(0xFFFFF4CF),
            child: Row(
              children: [
                Icon(Icons.science_outlined, color: KonaColors.goldDark),
                SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'Mock mode: retries simulate acceptance without touching production.',
                  ),
                ),
              ],
            ),
          ),
        drafts.when(
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
                  eyebrow: 'Device queue',
                  title:
                      '${items.length} operation${items.length == 1 ? '' : 's'}',
                ),
                const SizedBox(height: 12),
                if (items.isEmpty)
                  const EmptyState(
                    icon: Icons.cloud_done_outlined,
                    title: 'Nothing pending',
                    message: 'No local drafts or conflicts on this device.',
                  )
                else
                  ...items.map(
                    (draft) => Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: _DraftTile(
                        draft: draft,
                        busy: _activeId == draft.id,
                        onRetry: () => _retry(draft),
                        onDiscard: () =>
                            ref.read(draftStoreProvider).delete(draft.id),
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ),
        operations.when(
          loading: () => const KonaSectionCard(
            child: Center(child: CircularProgressIndicator()),
          ),
          error: (error, _) => KonaSectionCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const SectionHeading(
                  eyebrow: 'Server activity',
                  title: 'Could not load activity',
                ),
                const SizedBox(height: 8),
                Text(apiErrorMessage(error)),
              ],
            ),
          ),
          data: (items) => KonaSectionCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SectionHeading(
                  eyebrow: 'Server activity',
                  title: 'Recent operations (${items.length})',
                ),
                const SizedBox(height: 12),
                if (items.isEmpty)
                  const EmptyState(
                    icon: Icons.history_toggle_off_outlined,
                    title: 'No server activity yet',
                    message:
                        'Accepted, failed, and duplicate-safe submissions appear here.',
                  )
                else
                  ...items
                      .take(20)
                      .map(
                        (operation) => Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: _OperationTile(operation: operation),
                        ),
                      ),
              ],
            ),
          ),
        ),
        const KonaSectionCard(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.info_outline, color: KonaColors.info),
              SizedBox(width: 10),
              Expanded(
                child: Text(
                  'A balance conflict means another user changed stock while this device was offline. Review the latest quantity before retrying.',
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _OperationTile extends StatelessWidget {
  const _OperationTile({required this.operation});

  final MobileOperation operation;

  @override
  Widget build(BuildContext context) {
    final tone = switch (operation.status) {
      'succeeded' => StatusTone.success,
      'failed' || 'conflict' => StatusTone.danger,
      _ => StatusTone.warning,
    };
    final icon = operation.type.startsWith('handover.')
        ? Icons.swap_horiz
        : operation.type.startsWith('movement.')
        ? Icons.inventory_2_outlined
        : Icons.receipt_long_outlined;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: KonaColors.canvas,
        borderRadius: BorderRadius.circular(17),
        border: Border.all(color: KonaColors.line),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CircleAvatar(
            backgroundColor: KonaColors.soft,
            foregroundColor: KonaColors.ink,
            child: Icon(icon),
          ),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  operation.reference ?? _operationLabel(operation.type),
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 2),
                Text(
                  operation.message ?? _formatDate(operation.createdAt),
                  style: const TextStyle(color: KonaColors.muted, fontSize: 12),
                ),
                if (operation.message != null) ...[
                  const SizedBox(height: 2),
                  Text(
                    _formatDate(operation.createdAt),
                    style: const TextStyle(
                      color: KonaColors.muted,
                      fontSize: 11,
                    ),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(width: 8),
          StatusPill(label: operation.status, tone: tone),
        ],
      ),
    );
  }

  String _operationLabel(String type) => type
      .split('_')
      .where((part) => part.isNotEmpty)
      .map((part) => '${part[0].toUpperCase()}${part.substring(1)}')
      .join(' ');

  String _formatDate(DateTime value) {
    final local = value.toLocal();
    String two(int number) => number.toString().padLeft(2, '0');
    return '${local.year}-${two(local.month)}-${two(local.day)} '
        '${two(local.hour)}:${two(local.minute)}';
  }
}

class _DraftTile extends StatelessWidget {
  const _DraftTile({
    required this.draft,
    required this.busy,
    required this.onRetry,
    required this.onDiscard,
  });

  final PendingDraft draft;
  final bool busy;
  final VoidCallback onRetry;
  final VoidCallback onDiscard;

  @override
  Widget build(BuildContext context) {
    final tone = switch (draft.state) {
      'completed' => StatusTone.success,
      'conflict' || 'failed' => StatusTone.danger,
      _ => StatusTone.warning,
    };
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: KonaColors.canvas,
        borderRadius: BorderRadius.circular(17),
        border: Border.all(color: KonaColors.line),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const CircleAvatar(
                backgroundColor: KonaColors.soft,
                foregroundColor: KonaColors.ink,
                child: Icon(Icons.cloud_upload_outlined),
              ),
              const SizedBox(width: 11),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      draft.title,
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                    Text(
                      '${draft.lineCount} lines · ${draft.id.substring(0, 8)}',
                      style: const TextStyle(
                        color: KonaColors.muted,
                        fontSize: 12,
                      ),
                    ),
                  ],
                ),
              ),
              StatusPill(label: draft.state, tone: tone),
            ],
          ),
          if (draft.message != null) ...[
            const SizedBox(height: 8),
            Text(
              draft.message!,
              style: const TextStyle(color: KonaColors.muted, fontSize: 12),
            ),
          ],
          const SizedBox(height: 10),
          Row(
            children: [
              if (draft.state != 'completed')
                Expanded(
                  child: FilledButton.icon(
                    onPressed: busy ? null : onRetry,
                    icon: busy
                        ? const SizedBox.square(
                            dimension: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.refresh),
                    label: const Text('Retry'),
                  ),
                ),
              if (draft.state != 'completed') const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: busy ? null : onDiscard,
                  icon: const Icon(Icons.delete_outline),
                  label: Text(
                    draft.state == 'completed' ? 'Remove history' : 'Discard',
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
