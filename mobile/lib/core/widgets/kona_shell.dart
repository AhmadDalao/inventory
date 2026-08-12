import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../data/providers.dart';
import '../models/inventory_models.dart';
import '../theme/kona_theme.dart';

class KonaShell extends ConsumerStatefulWidget {
  const KonaShell({super.key, required this.location, required this.child});

  final String location;
  final Widget child;

  @override
  ConsumerState<KonaShell> createState() => _KonaShellState();
}

class _KonaShellState extends ConsumerState<KonaShell>
    with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      ref.invalidate(bootstrapProvider);
      ref.invalidate(handoversProvider);
    }
  }

  List<(String, String, IconData, IconData)> _destinations(
    MobileBootstrap? access,
  ) => [
    ('/home', 'Home', Icons.home_outlined, Icons.home_rounded),
    if (access?.canViewItems == true)
      (
        '/quantity',
        'Check',
        Icons.inventory_2_outlined,
        Icons.inventory_2_rounded,
      ),
    if (access?.hasScanOutAction == true || access?.canScanIn == true)
      (
        access?.hasScanOutAction == true ? '/scan-out' : '/scan-in',
        'Scan',
        Icons.qr_code_scanner_outlined,
        Icons.qr_code_scanner_rounded,
      ),
    if (access?.canViewHandovers == true)
      (
        '/handovers',
        'Handovers',
        Icons.swap_horiz_outlined,
        Icons.swap_horiz_rounded,
      ),
    ('/sync', 'Sync', Icons.sync_outlined, Icons.sync_rounded),
  ];

  int _selectedIndex(List<(String, String, IconData, IconData)> destinations) {
    final index = destinations.indexWhere(
      (destination) => widget.location.startsWith(destination.$1),
    );
    if (index >= 0) return index;

    String? targetLabel;
    if (widget.location.startsWith('/usage-cart') ||
        widget.location.startsWith('/scan-in') ||
        widget.location.startsWith('/scan-out') ||
        widget.location.startsWith('/scanner')) {
      targetLabel = 'Scan';
    } else if (widget.location.startsWith('/create-handover') ||
        widget.location.startsWith('/handovers/')) {
      targetLabel = 'Handovers';
    }
    if (targetLabel != null) {
      final related = destinations.indexWhere(
        (destination) => destination.$2 == targetLabel,
      );
      if (related >= 0) return related;
    }
    return 0;
  }

  @override
  Widget build(BuildContext context) {
    final access = ref.watch(bootstrapProvider).valueOrNull;
    final destinations = _destinations(access);
    final selectedIndex = _selectedIndex(destinations);
    return LayoutBuilder(
      builder: (context, constraints) {
        if (constraints.maxWidth >= 900) {
          return Scaffold(
            body: Row(
              children: [
                SafeArea(
                  child: NavigationRail(
                    backgroundColor: KonaColors.surface,
                    selectedIndex: selectedIndex,
                    onDestinationSelected: (index) =>
                        context.go(destinations[index].$1),
                    labelType: NavigationRailLabelType.all,
                    leading: Padding(
                      padding: const EdgeInsets.only(bottom: 20),
                      child: Image.asset(
                        'assets/brand/kona-logo.png',
                        width: 104,
                        height: 52,
                        fit: BoxFit.contain,
                      ),
                    ),
                    trailing: Expanded(
                      child: Align(
                        alignment: Alignment.bottomCenter,
                        child: IconButton.filledTonal(
                          onPressed: () => context.push('/settings'),
                          icon: const Icon(Icons.settings_outlined),
                        ),
                      ),
                    ),
                    destinations: destinations
                        .map(
                          (destination) => NavigationRailDestination(
                            icon: Icon(destination.$3),
                            selectedIcon: Icon(destination.$4),
                            label: Text(destination.$2),
                          ),
                        )
                        .toList(),
                  ),
                ),
                const VerticalDivider(width: 1),
                Expanded(child: widget.child),
              ],
            ),
          );
        }
        return Scaffold(
          body: widget.child,
          bottomNavigationBar: NavigationBar(
            selectedIndex: selectedIndex,
            onDestinationSelected: (index) =>
                context.go(destinations[index].$1),
            destinations: destinations
                .map(
                  (destination) => NavigationDestination(
                    icon: Icon(destination.$3),
                    selectedIcon: Icon(destination.$4),
                    label: destination.$2,
                  ),
                )
                .toList(),
          ),
        );
      },
    );
  }
}
