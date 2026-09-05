import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:inventory_kona/app.dart';
import 'package:inventory_kona/core/api/api_client.dart';
import 'package:inventory_kona/core/data/mock_inventory_repository.dart';
import 'package:inventory_kona/core/data/providers.dart';
import 'package:inventory_kona/core/models/inventory_models.dart';
import 'package:inventory_kona/core/security/biometric_authenticator.dart';
import 'package:inventory_kona/core/widgets/numeric_input.dart';
import 'package:inventory_kona/features/handovers/handover_closeout_screen.dart';
import 'package:inventory_kona/features/movements/usage_cart_screen.dart';
import 'package:inventory_kona/features/handovers/handover_receipt_screen.dart';
import 'package:inventory_kona/features/scanner/scan_hub_screen.dart';
import 'package:inventory_kona/features/settings/settings_screen.dart';

class _DisabledMobileRepository extends MockInventoryRepository {
  @override
  Future<void> login(
    String email,
    String password, {
    required bool keepSignedIn,
  }) async {
    throw const ApiFailure(
      'mobile_disabled',
      'Mobile access is currently disabled.',
    );
  }
}

class _PasswordCheckingRepository extends MockInventoryRepository {
  int verificationAttempts = 0;

  @override
  Future<void> verifyPassword(String password) async {
    verificationAttempts++;
    if (password != 'correct-password') {
      throw const ApiFailure('password_incorrect', 'Password is incorrect.');
    }
  }
}

class _UnavailableBiometricAuthenticator implements BiometricAuthenticator {
  @override
  Future<bool> get isAvailable async => false;

  @override
  Future<bool> authenticate({required String reason}) async => false;
}

class _TransientUsageRepository extends MockInventoryRepository {
  final List<String?> operationIds = [];

  @override
  Future<OperationReceipt> submitUsage({
    required int storageId,
    required List<CartLine> lines,
    required String defaultReason,
    String? defaultCustomReason,
    String? notes,
    String? proofPath,
    String? clientOperationId,
  }) async {
    operationIds.add(clientOperationId);
    if (operationIds.length == 1) {
      throw DioException(
        requestOptions: RequestOptions(path: '/movements/batch'),
        type: DioExceptionType.connectionError,
      );
    }
    return const OperationReceipt(
      reference: 'MOB-BATCH-42',
      status: 'completed',
    );
  }
}

class _BalanceConflictUsageRepository extends MockInventoryRepository {
  int bootstrapCalls = 0;
  final List<double?> submittedBalances = [];

  @override
  Future<MobileBootstrap> bootstrap() async {
    final data = await super.bootstrap();
    bootstrapCalls++;
    if (bootstrapCalls == 1) return data;
    return data.copyWith(
      items: [
        for (final item in data.items)
          if (item.id == 15 && item.storageId == 1)
            item.copyWith(quantity: 240)
          else
            item,
      ],
    );
  }

  @override
  Future<OperationReceipt> submitUsage({
    required int storageId,
    required List<CartLine> lines,
    required String defaultReason,
    String? defaultCustomReason,
    String? notes,
    String? proofPath,
    String? clientOperationId,
  }) async {
    submittedBalances.add(lines.single.expectedBalance);
    throw const ApiFailure(
      'balance_changed',
      'Stock changed since this item was reviewed.',
      retrySafe: true,
      details: {
        'item_id': 15,
        'storage_id': 1,
        'expected_balance': 246,
        'current_balance': 240,
      },
    );
  }
}

class _RejectingCloseoutRepository extends MockInventoryRepository {
  @override
  Future<HandoverDetail> handover(int id) async => const HandoverDetail(
    task: MobileTask(
      id: 104,
      reference: 'HDO-TEST-104',
      title: 'Close wristband handover',
      status: 'delivered',
      purpose: 'temporary_use',
      itemCount: 1,
      quantity: 10,
      source: 'KONA Main',
      allowedActions: {'report_closeout'},
      requiresAction: true,
    ),
    lines: [
      HandoverLine(
        id: 1040,
        itemId: 15,
        name: 'Blue Wristband',
        sku: 'WB-BLUE',
        unit: 'pcs',
        quantityIssued: 10,
        quantityReceived: 10,
        quantityUsed: 0,
        quantityReturned: 0,
        quantityHeld: 0,
      ),
    ],
  );

  @override
  Future<OperationReceipt> submitCloseout({
    required int handoverId,
    required Map<int, double> returnedQuantities,
    required Map<String, Map<String, double>> reconciliations,
    Map<String, String> discrepancyNotes = const {},
    String? notes,
    String? proofPath,
    String? clientOperationId,
  }) async {
    throw const ApiFailure(
      'reconciliation_mismatch',
      'Explain the wristband difference before submitting.',
    );
  }
}

void main() {
  testWidgets('tapping a prefilled numeric field selects its whole value', (
    tester,
  ) async {
    final controller = TextEditingController(text: '0');
    addTearDown(controller.dispose);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: TextField(
            key: const ValueKey('prefilled-quantity'),
            controller: controller,
            keyboardType: TextInputType.number,
            onTap: selectAllNumericTextOnTap,
          ),
        ),
      ),
    );

    await tester.tap(find.byKey(const ValueKey('prefilled-quantity')));
    await tester.pump();

    expect(
      controller.selection,
      const TextSelection(baseOffset: 0, extentOffset: 1),
    );
  });

  testWidgets('KONA production login renders without demo data', (
    tester,
  ) async {
    FlutterSecureStorage.setMockInitialValues({});
    await tester.pumpWidget(const ProviderScope(child: InventoryKonaApp()));
    await tester.pumpAndSettle();

    expect(find.text('Inventory access'), findsOneWidget);
    expect(find.text('Mock mode · no production data'), findsNothing);
    expect(find.text('Sign in'), findsOneWidget);
  });

  testWidgets('login shows a short actionable API error', (tester) async {
    FlutterSecureStorage.setMockInitialValues({});
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          inventoryRepositoryProvider.overrideWithValue(
            _DisabledMobileRepository(),
          ),
        ],
        child: const InventoryKonaApp(),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(ElevatedButton, 'Sign in'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('login-error')), findsOneWidget);
    expect(
      find.text(
        'The mobile app is not enabled yet. Ask the owner to enable Mobile Access on the website.',
      ),
      findsOneWidget,
    );
    expect(find.textContaining('DioException'), findsNothing);
    expect(find.textContaining('503'), findsNothing);
  });

  testWidgets('usage cart starts empty and renders every configured reason', (
    tester,
  ) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          inventoryRepositoryProvider.overrideWithValue(
            MockInventoryRepository(),
          ),
        ],
        child: const MaterialApp(home: UsageCartScreen()),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('usage-cart-empty')), findsOneWidget);
    expect(find.textContaining('No demo stock is inserted.'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('usage-default-reason-school')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('usage-default-reason-no_show')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('usage-default-reason-other')),
      findsOneWidget,
    );

    final otherReason = find.byKey(
      const ValueKey('usage-default-reason-other'),
    );
    final otherChip = tester.widget<ChoiceChip>(otherReason);
    otherChip.onSelected?.call(true);
    await tester.pumpAndSettle();
    expect(find.text('Describe Other'), findsOneWidget);
  });

  testWidgets('usage retry reuses its operation ID after a network failure', (
    tester,
  ) async {
    final repository = _TransientUsageRepository();
    final router = GoRouter(
      routes: [
        GoRoute(path: '/', builder: (_, _) => const UsageCartScreen()),
        GoRoute(path: '/home', builder: (_, _) => const Scaffold()),
      ],
    );
    addTearDown(router.dispose);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [inventoryRepositoryProvider.overrideWithValue(repository)],
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(TextButton, 'Add'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Blue Wristband').last);
    await tester.pumpAndSettle();
    final submit = find.widgetWithText(ElevatedButton, 'Submit 1 units');
    await tester.ensureVisible(submit);
    await tester.tap(submit);
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('usage-submit-error-dialog')),
      findsOneWidget,
    );
    expect(find.textContaining('reuse the same operation ID'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'Review cart'));
    await tester.pumpAndSettle();
    await tester.tap(submit);
    await tester.pumpAndSettle();

    expect(repository.operationIds, hasLength(2));
    expect(repository.operationIds.first, isNotNull);
    expect(repository.operationIds.last, repository.operationIds.first);
    expect(find.text('Usage submitted'), findsOneWidget);
  });

  testWidgets('usage conflict reloads the balance before a retry', (
    tester,
  ) async {
    final repository = _BalanceConflictUsageRepository();
    final router = GoRouter(
      routes: [
        GoRoute(path: '/', builder: (_, _) => const UsageCartScreen()),
        GoRoute(path: '/home', builder: (_, _) => const Scaffold()),
      ],
    );
    addTearDown(router.dispose);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [inventoryRepositoryProvider.overrideWithValue(repository)],
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(TextButton, 'Add'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Blue Wristband').last);
    await tester.pumpAndSettle();
    final submit = find.widgetWithText(ElevatedButton, 'Submit 1 units');
    await tester.ensureVisible(submit);
    await tester.tap(submit);
    await tester.pumpAndSettle();

    expect(find.textContaining('latest balance is now loaded'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'Review cart'));
    await tester.pumpAndSettle();
    await tester.tap(submit);
    await tester.pumpAndSettle();

    expect(repository.submittedBalances, [246, 240]);
  });

  testWidgets('quick scan exposes direct usage and refill actions', (
    tester,
  ) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          inventoryRepositoryProvider.overrideWithValue(
            MockInventoryRepository(),
          ),
        ],
        child: const MaterialApp(home: ScanHubScreen()),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('quick-scan-use')), findsOneWidget);
    expect(find.byKey(const ValueKey('quick-scan-refill')), findsOneWidget);
    expect(find.text('Use from storage'), findsOneWidget);
    expect(find.text('Refill storage'), findsOneWidget);
  });

  for (final width in [390.0, 430.0, 768.0]) {
    testWidgets('quick scan fits a ${width.toInt()}px viewport', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(Size(width, 844));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            inventoryRepositoryProvider.overrideWithValue(
              MockInventoryRepository(),
            ),
          ],
          child: const MaterialApp(home: ScanHubScreen()),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('quick-scan-use')), findsOneWidget);
      expect(find.byKey(const ValueKey('quick-scan-refill')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('handover recipient can record receipt notes', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          inventoryRepositoryProvider.overrideWithValue(
            MockInventoryRepository(),
          ),
        ],
        child: const MaterialApp(home: HandoverReceiptScreen(handoverId: 101)),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Receipt notes (optional)'), findsOneWidget);
    expect(
      find.textContaining('Mention shortages, extra items'),
      findsOneWidget,
    );
  });

  testWidgets('handover closeout shows a rejected submission', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          inventoryRepositoryProvider.overrideWithValue(
            _RejectingCloseoutRepository(),
          ),
        ],
        child: const MaterialApp(home: HandoverCloseoutScreen(handoverId: 104)),
      ),
    );
    await tester.pumpAndSettle();

    final submit = find.widgetWithText(
      ElevatedButton,
      'Submit for issuer approval',
    );
    await tester.tap(submit);
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('handover-closeout-submit-error-dialog')),
      findsOneWidget,
    );
    expect(
      find.text('Explain the wristband difference before submitting.'),
      findsOneWidget,
    );
    await tester.tap(find.widgetWithText(FilledButton, 'Review closeout'));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('handover-closeout-submit-error-dialog')),
      findsNothing,
    );
    expect(tester.widget<ElevatedButton>(submit).onPressed, isNotNull);
  });

  testWidgets('persistent sign-in requires the current password', (
    tester,
  ) async {
    FlutterSecureStorage.setMockInitialValues({});
    final repository = _PasswordCheckingRepository();

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          inventoryRepositoryProvider.overrideWithValue(repository),
          biometricAuthenticatorProvider.overrideWithValue(
            _UnavailableBiometricAuthenticator(),
          ),
        ],
        child: const MaterialApp(home: SettingsScreen()),
      ),
    );
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.text('Keep me signed in'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Keep me signed in'));
    await tester.pumpAndSettle();

    expect(find.text('Confirm your password'), findsOneWidget);
    expect(repository.verificationAttempts, 0);

    await tester.enterText(
      find.byKey(const ValueKey('keep-signed-in-password')),
      'wrong-password',
    );
    await tester.tap(find.byKey(const ValueKey('confirm-keep-signed-in')));
    await tester.pumpAndSettle();

    expect(find.text('Password is incorrect.'), findsOneWidget);
    expect(repository.verificationAttempts, 1);

    await tester.enterText(
      find.byKey(const ValueKey('keep-signed-in-password')),
      'correct-password',
    );
    await tester.tap(find.byKey(const ValueKey('confirm-keep-signed-in')));
    await tester.pumpAndSettle();

    expect(find.text('Confirm your password'), findsNothing);
    expect(find.text('Secure token storage enabled.'), findsOneWidget);
    expect(repository.verificationAttempts, 2);
  });
}
