import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:inventory_kona/app.dart';

void main() {
  testWidgets('KONA mock login renders', (tester) async {
    await tester.pumpWidget(const ProviderScope(child: InventoryKonaApp()));
    await tester.pumpAndSettle();

    expect(find.text('Inventory access'), findsOneWidget);
    expect(find.text('Mock mode · no production data'), findsOneWidget);
    expect(find.text('Sign in'), findsOneWidget);
  });
}
