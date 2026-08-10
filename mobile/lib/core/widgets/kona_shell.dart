import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../theme/kona_theme.dart';

class KonaShell extends StatelessWidget {
  const KonaShell({super.key, required this.location, required this.child});

  final String location;
  final Widget child;

  static const destinations = [
    ('/home', 'Home', Icons.home_outlined, Icons.home_rounded),
    (
      '/quantity',
      'Check',
      Icons.inventory_2_outlined,
      Icons.inventory_2_rounded,
    ),
    (
      '/scan-out',
      'Scan',
      Icons.qr_code_scanner_outlined,
      Icons.qr_code_scanner_rounded,
    ),
    (
      '/handovers',
      'Handovers',
      Icons.swap_horiz_outlined,
      Icons.swap_horiz_rounded,
    ),
    ('/sync', 'Sync', Icons.sync_outlined, Icons.sync_rounded),
  ];

  int get selectedIndex {
    final index = destinations.indexWhere(
      (destination) => location.startsWith(destination.$1),
    );
    if (index >= 0) return index;
    if (location.startsWith('/usage-cart') ||
        location.startsWith('/scan-in') ||
        location.startsWith('/create-handover') ||
        location.startsWith('/scanner')) {
      return 2;
    }
    return 0;
  }

  @override
  Widget build(BuildContext context) {
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
                Expanded(child: child),
              ],
            ),
          );
        }
        return Scaffold(
          body: child,
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
