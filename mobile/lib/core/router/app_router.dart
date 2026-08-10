import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/login_screen.dart';
import '../../features/handovers/create_handover_screen.dart';
import '../../features/handovers/custody_return_screen.dart';
import '../../features/handovers/handover_closeout_screen.dart';
import '../../features/handovers/handover_detail_screen.dart';
import '../../features/handovers/handover_list_screen.dart';
import '../../features/handovers/handover_receipt_screen.dart';
import '../../features/inventory/home_screen.dart';
import '../../features/inventory/quantity_check_screen.dart';
import '../../features/movements/scan_in_screen.dart';
import '../../features/movements/usage_cart_screen.dart';
import '../../features/scanner/scan_out_screen.dart';
import '../../features/scanner/scanner_screen.dart';
import '../../features/settings/settings_screen.dart';
import '../../features/sync/sync_center_screen.dart';
import '../widgets/kona_shell.dart';

final appRouterProvider = Provider<GoRouter>((ref) {
  final router = GoRouter(
    initialLocation: '/login',
    routes: [
      GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
      GoRoute(
        path: '/scanner/:mode',
        builder: (_, state) =>
            ScannerScreen(mode: state.pathParameters['mode'] ?? 'item'),
      ),
      ShellRoute(
        builder: (_, state, child) =>
            KonaShell(location: state.uri.path, child: child),
        routes: [
          GoRoute(path: '/home', builder: (_, _) => const HomeScreen()),
          GoRoute(
            path: '/quantity',
            builder: (_, _) => const QuantityCheckScreen(),
          ),
          GoRoute(path: '/scan-out', builder: (_, _) => const ScanOutScreen()),
          GoRoute(path: '/scan-in', builder: (_, _) => const ScanInScreen()),
          GoRoute(
            path: '/usage-cart',
            builder: (_, _) => const UsageCartScreen(),
          ),
          GoRoute(
            path: '/handovers',
            builder: (_, _) => const HandoverListScreen(),
          ),
          GoRoute(
            path: '/create-handover',
            builder: (_, state) => CreateHandoverScreen(
              purpose: state.uri.queryParameters['purpose'],
            ),
          ),
          GoRoute(
            path: '/handovers/:id',
            builder: (_, state) => HandoverDetailScreen(
              handoverId: int.parse(state.pathParameters['id']!),
            ),
          ),
          GoRoute(
            path: '/handovers/:id/receipt',
            builder: (_, state) => HandoverReceiptScreen(
              handoverId: int.parse(state.pathParameters['id']!),
              issuerReview: state.uri.queryParameters['review'] == '1',
            ),
          ),
          GoRoute(
            path: '/handovers/:id/closeout',
            builder: (_, state) => HandoverCloseoutScreen(
              handoverId: int.parse(state.pathParameters['id']!),
              issuerApproval: state.uri.queryParameters['approval'] == '1',
            ),
          ),
          GoRoute(
            path: '/handovers/:id/custody-return',
            builder: (_, state) => CustodyReturnScreen(
              handoverId: int.parse(state.pathParameters['id']!),
            ),
          ),
          GoRoute(path: '/sync', builder: (_, _) => const SyncCenterScreen()),
          GoRoute(path: '/settings', builder: (_, _) => const SettingsScreen()),
        ],
      ),
    ],
  );
  ref.onDispose(router.dispose);
  return router;
});
