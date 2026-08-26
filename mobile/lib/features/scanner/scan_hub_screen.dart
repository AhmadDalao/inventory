import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/data/providers.dart';
import '../../core/theme/kona_theme.dart';
import '../../core/widgets/kona_page.dart';
import '../../core/widgets/status_widgets.dart';

class ScanHubScreen extends ConsumerWidget {
  const ScanHubScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ref
        .watch(bootstrapProvider)
        .when(
          loading: () => const ColoredBox(
            color: KonaColors.canvas,
            child: Center(child: CircularProgressIndicator()),
          ),
          error: (error, _) => ColoredBox(
            color: KonaColors.canvas,
            child: SafeArea(
              child: Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: EmptyState(
                    icon: Icons.cloud_off_outlined,
                    title: 'Scan access unavailable',
                    message: apiErrorMessage(error),
                  ),
                ),
              ),
            ),
          ),
          data: (access) {
            final primaryActions = <_ScanActionSpec>[
              if (access.canUseStock)
                _ScanActionSpec(
                  key: const ValueKey('quick-scan-use'),
                  icon: Icons.remove_circle_outline_rounded,
                  title: 'Use from storage',
                  description:
                      'Scan consumed items, choose the package and reason, then review before posting.',
                  onTap: () => context.push('/usage-cart'),
                  color: KonaColors.gold,
                ),
              if (access.canRestock)
                _ScanActionSpec(
                  key: const ValueKey('quick-scan-refill'),
                  icon: Icons.add_box_outlined,
                  title: 'Refill storage',
                  description:
                      'Scan incoming stock, confirm its package and proof, then review before adding it.',
                  onTap: () => context.push('/refill-cart'),
                  color: KonaColors.ink,
                  foregroundColor: Colors.white,
                ),
            ];
            final secondaryActions = <_ScanActionSpec>[
              if (access.canScanIn)
                _ScanActionSpec(
                  key: const ValueKey('quick-scan-receive'),
                  icon: Icons.move_to_inbox_outlined,
                  title: 'Receive handover or transfer',
                  description:
                      'Scan a reference and report the exact quantity received.',
                  onTap: () => context.push('/scan-in'),
                ),
              if (access.canCreateAnyHandover)
                _ScanActionSpec(
                  key: const ValueKey('quick-scan-handover'),
                  icon: Icons.outbox_outlined,
                  title: 'Handover or transfer stock',
                  description:
                      'Scan items into a controlled handover or storage transfer.',
                  onTap: () => context.push('/scan-out'),
                ),
              if (access.canViewItems)
                _ScanActionSpec(
                  key: const ValueKey('quick-scan-check'),
                  icon: Icons.inventory_2_outlined,
                  title: 'Check quantity',
                  description:
                      'Scan or search an item and see its quantity in your assigned storage.',
                  onTap: () => context.push('/quantity'),
                ),
            ];

            if (primaryActions.isEmpty && secondaryActions.isEmpty) {
              return const ColoredBox(
                color: KonaColors.canvas,
                child: SafeArea(
                  child: Center(
                    child: Padding(
                      padding: EdgeInsets.all(24),
                      child: AccessDeniedState(
                        message:
                            'No scan actions are enabled for your account or assigned storage.',
                      ),
                    ),
                  ),
                ),
              );
            }

            return KonaPage(
              eyebrow: 'Fast field work',
              title: 'Quick scan',
              description:
                  'Choose what is happening to stock. Scanning never changes quantity until you review and confirm.',
              children: [
                if (primaryActions.isNotEmpty)
                  KonaSectionCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        const SectionHeading(
                          eyebrow: 'Everyday actions',
                          title: 'Use or refill inventory',
                        ),
                        const SizedBox(height: 14),
                        LayoutBuilder(
                          builder: (context, constraints) {
                            final cardWidth = constraints.maxWidth >= 680
                                ? (constraints.maxWidth - 12) / 2
                                : constraints.maxWidth;
                            return Wrap(
                              spacing: 12,
                              runSpacing: 12,
                              children: [
                                for (final action in primaryActions)
                                  SizedBox(
                                    width: cardWidth,
                                    child: _ScanActionCard(action: action),
                                  ),
                              ],
                            );
                          },
                        ),
                      ],
                    ),
                  ),
                if (secondaryActions.isNotEmpty)
                  KonaSectionCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        const SectionHeading(
                          eyebrow: 'Accountable workflows',
                          title: 'Receive, hand over, or check',
                        ),
                        const SizedBox(height: 14),
                        for (
                          var index = 0;
                          index < secondaryActions.length;
                          index++
                        ) ...[
                          if (index > 0) const SizedBox(height: 10),
                          _ScanActionCard(action: secondaryActions[index]),
                        ],
                      ],
                    ),
                  ),
                const KonaSectionCard(
                  color: KonaColors.soft,
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(
                        Icons.verified_user_outlined,
                        color: KonaColors.goldDark,
                      ),
                      SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          'Your assigned storages and permissions are checked again by the server when you submit.',
                          style: TextStyle(color: KonaColors.muted),
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

class _ScanActionSpec {
  const _ScanActionSpec({
    required this.key,
    required this.icon,
    required this.title,
    required this.description,
    required this.onTap,
    this.color = KonaColors.surface,
    this.foregroundColor = KonaColors.ink,
  });

  final Key key;
  final IconData icon;
  final String title;
  final String description;
  final VoidCallback onTap;
  final Color color;
  final Color foregroundColor;
}

class _ScanActionCard extends StatelessWidget {
  const _ScanActionCard({required this.action});

  final _ScanActionSpec action;

  @override
  Widget build(BuildContext context) => Card(
    key: action.key,
    color: action.color,
    clipBehavior: Clip.antiAlias,
    child: InkWell(
      onTap: action.onTap,
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Row(
          children: [
            Container(
              width: 48,
              height: 48,
              decoration: BoxDecoration(
                color: action.foregroundColor.withValues(alpha: .1),
                borderRadius: BorderRadius.circular(15),
              ),
              child: Icon(action.icon, color: action.foregroundColor),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    action.title,
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      color: action.foregroundColor,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    action.description,
                    style: TextStyle(
                      color: action.foregroundColor.withValues(alpha: .72),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            Icon(Icons.arrow_forward_rounded, color: action.foregroundColor),
          ],
        ),
      ),
    ),
  );
}
