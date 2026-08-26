import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:inventory_kona/app.dart';
import 'package:inventory_kona/core/api/api_client.dart';
import 'package:inventory_kona/core/data/mock_inventory_repository.dart';
import 'package:inventory_kona/core/data/providers.dart';
import 'package:inventory_kona/core/security/biometric_authenticator.dart';
import 'package:inventory_kona/core/widgets/numeric_input.dart';
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
