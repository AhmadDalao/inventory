import 'package:flutter/material.dart';
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
import '../../features/movements/refill_cart_screen.dart';
import '../../features/movements/usage_cart_screen.dart';
import '../../features/scanner/scan_hub_screen.dart';
import '../../features/scanner/scan_out_screen.dart';
import '../../features/scanner/scanner_screen.dart';
import '../../features/settings/settings_screen.dart';
import '../../features/sync/sync_center_screen.dart';
import '../data/providers.dart';
import '../models/inventory_models.dart';
import '../widgets/kona_shell.dart';
import '../widgets/status_widgets.dart';

Widget _guarded(
  Widget child,
  bool Function(MobileBootstrap bootstrap) allow,
  String message,
) => MobileAccessGate(allow: allow, message: message, child: child);

final appRouterProvider = Provider<GoRouter>((ref) {
  final sessionRevoked = ref.watch(mobileSessionRevokedProvider);
  final router = GoRouter(
    initialLocation: '/login',
    redirect: (_, state) {
      if (sessionRevoked && state.uri.path != '/login') return '/login';
      return null;
    },
    routes: [
      GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
      GoRoute(
        path: '/scanner/:mode',
        builder: (_, state) {
          final mode = state.pathParameters['mode'] ?? 'item';
          return _guarded(
            ScannerScreen(mode: mode),
            mode == 'reference'
                ? (access) => access.canScanIn || access.canViewHandovers
                : (access) => access.canViewItems,
            mode == 'reference'
                ? 'Reference scanning requires handover access.'
                : 'Item scanning requires inventory access.',
          );
        },
      ),
      ShellRoute(
        builder: (_, state, child) =>
            KonaShell(location: state.uri.path, child: child),
        routes: [
          GoRoute(path: '/home', builder: (_, _) => const HomeScreen()),
          GoRoute(
            path: '/quantity',
            builder: (_, state) => _guarded(
              QuantityCheckScreen(
                initialStorageId: int.tryParse(
                  state.uri.queryParameters['storage_id'] ?? '',
                ),
              ),
              (access) => access.canViewItems,
              'Quantity checks require item access and an assigned storage.',
            ),
          ),
          GoRoute(
            path: '/scan',
            builder: (_, _) => _guarded(
              const ScanHubScreen(),
              (access) =>
                  access.canUseStock ||
                  access.canRestock ||
                  access.canScanIn ||
                  access.canCreateAnyHandover,
              'No scan actions are enabled for your account.',
            ),
          ),
          GoRoute(
            path: '/scan-out',
            builder: (_, _) => _guarded(
              const ScanOutScreen(),
              (access) => access.hasScanOutAction,
              'Scan Out requires usage, transfer, handover, or custody access.',
            ),
          ),
          GoRoute(
            path: '/scan-in',
            builder: (_, _) => _guarded(
              const ScanInScreen(),
              (access) => access.canScanIn || access.canRestock,
              'Scan In requires receipt access or direct refill permission.',
            ),
          ),
          GoRoute(
            path: '/refill-cart',
            builder: (_, _) => _guarded(
              const RefillCartScreen(),
              (access) => access.canRestock,
              'Direct refill is not enabled for your account.',
            ),
          ),
          GoRoute(
            path: '/usage-cart',
            builder: (_, _) => _guarded(
              const UsageCartScreen(),
              (access) => access.canUseStock,
              'Usage reporting is not enabled for your account.',
            ),
          ),
          GoRoute(
            path: '/handovers',
            builder: (_, _) => _guarded(
              const HandoverListScreen(),
              (access) => access.canViewHandovers,
              'You do not have permission to view handovers.',
            ),
          ),
          GoRoute(
            path: '/create-handover',
            builder: (_, state) => _guarded(
              CreateHandoverScreen(
                purpose: state.uri.queryParameters['purpose'],
              ),
              (access) => access.canCreateAnyHandover,
              'Creating handovers is not enabled for your account.',
            ),
          ),
          GoRoute(
            path: '/handovers/:id',
            builder: (_, state) => _guarded(
              HandoverDetailScreen(
                handoverId: int.parse(state.pathParameters['id']!),
              ),
              (access) => access.canViewHandovers,
              'You do not have permission to view handovers.',
            ),
          ),
          GoRoute(
            path: '/handovers/:id/receipt',
            builder: (_, state) {
              final issuerReview = state.uri.queryParameters['review'] == '1';
              return _guarded(
                HandoverReceiptScreen(
                  handoverId: int.parse(state.pathParameters['id']!),
                  issuerReview: issuerReview,
                ),
                issuerReview
                    ? (access) => access.canApproveHandovers
                    : (access) => access.canReceiveHandovers,
                issuerReview
                    ? 'Receipt review requires handover approval access.'
                    : 'Receipt confirmation is not enabled for your account.',
              );
            },
          ),
          GoRoute(
            path: '/handovers/:id/closeout',
            builder: (_, state) {
              final issuerApproval =
                  state.uri.queryParameters['approval'] == '1';
              return _guarded(
                HandoverCloseoutScreen(
                  handoverId: int.parse(state.pathParameters['id']!),
                  issuerApproval: issuerApproval,
                ),
                issuerApproval
                    ? (access) => access.canApproveHandovers
                    : (access) => access.canReceiveHandovers,
                issuerApproval
                    ? 'Final review requires handover approval access.'
                    : 'Usage reporting is not enabled for your account.',
              );
            },
          ),
          GoRoute(
            path: '/handovers/:id/custody-return',
            builder: (_, state) => _guarded(
              CustodyReturnScreen(
                handoverId: int.parse(state.pathParameters['id']!),
              ),
              (access) => access.canReturnCustody,
              'Custody returns are not enabled for your account.',
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
