import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/config/app_config.dart';
import '../../core/data/providers.dart';
import '../../core/theme/kona_theme.dart';
import '../../core/widgets/kona_page.dart';
import '../../core/widgets/status_widgets.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final bootstrap = ref.watch(bootstrapProvider);
    final drafts = ref.watch(pendingDraftsProvider).valueOrNull ?? const [];
    final pendingDraftCount = drafts
        .where((draft) => draft.state != 'completed')
        .length;
    return bootstrap.when(
      loading: () =>
          const Scaffold(body: Center(child: CircularProgressIndicator())),
      error: (error, _) =>
          Scaffold(body: Center(child: Text(error.toString()))),
      data: (data) {
        final storage = data.defaultStorage;
        final storageItems = data.items
            .where((item) => item.storageId == storage?.id)
            .toList();
        final units = storageItems.fold<double>(
          0,
          (sum, item) => sum + item.quantity,
        );
        final low = storageItems
            .where((item) => item.quantity <= item.reorderLevel)
            .length;
        final actions = data.tasks.where((task) => task.requiresAction).length;
        return KonaPage(
          eyebrow: 'My day',
          title: 'Welcome, ${data.userName}',
          description: AppConfig.mockMode
              ? 'Clickable prototype using safe fixture data.'
              : 'Live data from your assigned inventory.',
          trailing: IconButton.filledTonal(
            onPressed: () => context.push('/settings'),
            icon: const Icon(Icons.settings_outlined),
          ),
          children: [
            KonaSectionCard(
              color: KonaColors.ink,
              child: Row(
                children: [
                  const Icon(
                    Icons.warehouse_outlined,
                    color: KonaColors.gold,
                    size: 32,
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'DEFAULT STORAGE',
                          style: TextStyle(
                            color: KonaColors.gold,
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                            letterSpacing: 1,
                          ),
                        ),
                        Text(
                          storage?.name ?? 'No storage assigned',
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 22,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        Text(
                          '${_number(units)} units across ${storageItems.length} items',
                          style: const TextStyle(color: Colors.white70),
                        ),
                      ],
                    ),
                  ),
                  const Icon(Icons.chevron_right, color: Colors.white54),
                ],
              ),
            ),
            LayoutBuilder(
              builder: (context, constraints) {
                final width = (constraints.maxWidth - 12) / 2;
                return Wrap(
                  spacing: 12,
                  runSpacing: 12,
                  children: [
                    _Metric(
                      width: width,
                      label: 'Need action',
                      value: '$actions',
                      icon: Icons.notifications_active_outlined,
                      tone: actions > 0 ? KonaColors.gold : KonaColors.success,
                    ),
                    _Metric(
                      width: width,
                      label: 'Low stock',
                      value: '$low',
                      icon: Icons.trending_down,
                      tone: low > 0 ? KonaColors.danger : KonaColors.success,
                    ),
                    _Metric(
                      width: width,
                      label: 'Offline drafts',
                      value: '$pendingDraftCount',
                      icon: Icons.cloud_off_outlined,
                      tone: KonaColors.info,
                    ),
                    _Metric(
                      width: width,
                      label: 'Last sync',
                      value: 'Now',
                      icon: Icons.sync,
                      tone: KonaColors.success,
                    ),
                  ],
                );
              },
            ),
            KonaSectionCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SectionHeading(
                    eyebrow: 'Field work',
                    title: 'Quick actions',
                  ),
                  const SizedBox(height: 15),
                  Row(
                    children: [
                      Expanded(
                        child: _Action(
                          icon: Icons.qr_code_scanner,
                          label: 'Scan out',
                          onTap: () => context.go('/scan-out'),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: _Action(
                          icon: Icons.move_to_inbox_outlined,
                          label: 'Scan in',
                          onTap: () => context.push('/scan-in'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: _Action(
                          icon: Icons.search,
                          label: 'Check quantity',
                          onTap: () => context.go('/quantity'),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: _Action(
                          icon: Icons.swap_horiz,
                          label: 'New handover',
                          onTap: () => context.push('/create-handover'),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            KonaSectionCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  SectionHeading(
                    eyebrow: 'Tasks',
                    title: 'Waiting for you',
                    trailing: TextButton(
                      onPressed: () => context.go('/handovers'),
                      child: const Text('View all'),
                    ),
                  ),
                  const SizedBox(height: 10),
                  if (data.tasks.where((task) => task.requiresAction).isEmpty)
                    const EmptyState(
                      icon: Icons.task_alt,
                      title: 'Nothing waiting',
                      message: 'You are caught up.',
                    )
                  else
                    ...data.tasks
                        .where((task) => task.requiresAction)
                        .take(3)
                        .map(
                          (task) => Padding(
                            padding: const EdgeInsets.only(top: 9),
                            child: InkWell(
                              onTap: () =>
                                  context.push('/handovers/${task.id}'),
                              borderRadius: BorderRadius.circular(16),
                              child: Container(
                                padding: const EdgeInsets.all(14),
                                decoration: BoxDecoration(
                                  color: KonaColors.canvas,
                                  borderRadius: BorderRadius.circular(16),
                                ),
                                child: Row(
                                  children: [
                                    const CircleAvatar(
                                      backgroundColor: KonaColors.soft,
                                      foregroundColor: KonaColors.ink,
                                      child: Icon(Icons.assignment_outlined),
                                    ),
                                    const SizedBox(width: 12),
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        children: [
                                          Text(
                                            task.title,
                                            style: const TextStyle(
                                              fontWeight: FontWeight.w700,
                                            ),
                                          ),
                                          Text(
                                            '${task.reference} · ${_number(task.quantity)} units',
                                            style: const TextStyle(
                                              color: KonaColors.muted,
                                              fontSize: 12,
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                    const StatusPill(
                                      label: 'Action',
                                      tone: StatusTone.warning,
                                    ),
                                  ],
                                ),
                              ),
                            ),
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

  String _number(double value) => value == value.roundToDouble()
      ? value.toInt().toString()
      : value.toStringAsFixed(2);
}

class _Metric extends StatelessWidget {
  const _Metric({
    required this.width,
    required this.label,
    required this.value,
    required this.icon,
    required this.tone,
  });
  final double width;
  final String label;
  final String value;
  final IconData icon;
  final Color tone;

  @override
  Widget build(BuildContext context) => SizedBox(
    width: width,
    child: KonaSectionCard(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: tone, size: 23),
          const SizedBox(height: 12),
          Text(value, style: Theme.of(context).textTheme.headlineMedium),
          Text(label, style: const TextStyle(color: KonaColors.muted)),
        ],
      ),
    ),
  );
}

class _Action extends StatelessWidget {
  const _Action({required this.icon, required this.label, required this.onTap});
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => OutlinedButton(
    onPressed: onTap,
    style: OutlinedButton.styleFrom(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 16),
    ),
    child: Column(
      children: [
        Icon(icon),
        const SizedBox(height: 7),
        Text(label, textAlign: TextAlign.center),
      ],
    ),
  );
}
