import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/router/app_router.dart';
import 'core/theme/kona_theme.dart';

class InventoryKonaApp extends ConsumerWidget {
  const InventoryKonaApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return MaterialApp.router(
      title: 'Inventory KONA',
      debugShowCheckedModeBanner: false,
      theme: KonaTheme.light,
      routerConfig: ref.watch(appRouterProvider),
    );
  }
}
