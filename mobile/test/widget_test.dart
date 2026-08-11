import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:inventory_kona/app.dart';
import 'package:inventory_kona/core/api/api_client.dart';
import 'package:inventory_kona/core/data/mock_inventory_repository.dart';
import 'package:inventory_kona/core/data/providers.dart';

class _DisabledMobileRepository extends MockInventoryRepository {
  @override
  Future<void> login(String email, String password) async {
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
}
