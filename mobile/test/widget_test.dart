import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:inventory_kona/app.dart';
import 'package:inventory_kona/core/api/api_client.dart';
import 'package:inventory_kona/core/data/mock_inventory_repository.dart';
import 'package:inventory_kona/core/data/providers.dart';
import 'package:inventory_kona/features/movements/usage_cart_screen.dart';

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

void main() {
  testWidgets('KONA mock login renders', (tester) async {
    await tester.pumpWidget(const ProviderScope(child: InventoryKonaApp()));
    await tester.pumpAndSettle();

    expect(find.text('Inventory access'), findsOneWidget);
    expect(find.text('Mock mode · no production data'), findsOneWidget);
    expect(find.text('Sign in'), findsOneWidget);
  });

  testWidgets('login shows a short actionable API error', (tester) async {
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
}
