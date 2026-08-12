import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/data/providers.dart';
import '../../core/theme/kona_theme.dart';
import '../../core/widgets/kona_page.dart';
import '../../core/widgets/status_widgets.dart';

class ScanOutScreen extends ConsumerStatefulWidget {
  const ScanOutScreen({super.key});

  @override
  ConsumerState<ScanOutScreen> createState() => _ScanOutScreenState();
}

class _ScanOutScreenState extends ConsumerState<ScanOutScreen> {
  String _action = 'usage';

  @override
  Widget build(BuildContext context) {
    final data = ref.watch(bootstrapProvider).valueOrNull;
    final options = [
      if (data?.canUseStock == true)
        (
        'usage',
        'Use / consume',
        'Deduct consumed stock with an operational reason.',
        Icons.remove_circle_outline,
      ),
      if (data?.canCreateTransfer == true)
        (
        'transfer',
        'Transfer to storage',
        'Send stock through accountable destination receipt.',
        Icons.warehouse_outlined,
      ),
      if (data?.canCreateTemporaryHandover == true)
        (
        'handover',
        'Handover to staff',
        'Issue stock for temporary operational use.',
        Icons.person_outline,
      ),
      if (data?.canCreateCustody == true)
        (
        'custody',
        'Long-term custody',
        'Assign stock for weeks or months with return tracking.',
        Icons.assignment_ind_outlined,
      ),
    ];
    final action = options.any((option) => option.$1 == _action)
        ? _action
        : options.firstOrNull?.$1;
    return KonaPage(
      eyebrow: 'Choose first',
      title: 'Scan out',
      description:
          'The app never guesses why stock is leaving. Pick the operation, scan into a cart, then review.',
      bottomAction: ElevatedButton.icon(
        onPressed: action == null
            ? null
            : () {
          if (action == 'usage') {
            context.push('/usage-cart');
          } else {
            context.push('/create-handover?purpose=$action');
          }
        },
        icon: const Icon(Icons.qr_code_scanner),
        label: const Text('Start scanning'),
      ),
      children: [
        if (options.isEmpty)
          const KonaSectionCard(child: AccessDeniedState()),
        ...options.map(
          (option) => _ActionCard(
            selected: action == option.$1,
            title: option.$2,
            description: option.$3,
            icon: option.$4,
            onTap: () => setState(() => _action = option.$1),
          ),
        ),
        KonaSectionCard(
          color: const Color(0xFFFFF4CF),
          child: const Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.shield_outlined, color: KonaColors.goldDark),
              SizedBox(width: 11),
              Expanded(
                child: Text(
                  'Nothing posts while scanning. Every line stays in a review cart until the server validates it.',
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _ActionCard extends StatelessWidget {
  const _ActionCard({
    required this.selected,
    required this.title,
    required this.description,
    required this.icon,
    required this.onTap,
  });
  final bool selected;
  final String title;
  final String description;
  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Material(
    color: selected ? const Color(0xFFFFF2C1) : KonaColors.surface,
    borderRadius: BorderRadius.circular(20),
    child: InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(20),
      child: Container(
        padding: const EdgeInsets.all(17),
        decoration: BoxDecoration(
          border: Border.all(
            color: selected ? KonaColors.gold : KonaColors.line,
            width: selected ? 2 : 1,
          ),
          borderRadius: BorderRadius.circular(20),
        ),
        child: Row(
          children: [
            CircleAvatar(
              backgroundColor: selected ? KonaColors.gold : KonaColors.soft,
              foregroundColor: KonaColors.ink,
              child: Icon(icon),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 3),
                  Text(
                    description,
                    style: const TextStyle(color: KonaColors.muted),
                  ),
                ],
              ),
            ),
            Icon(
              selected ? Icons.check_circle : Icons.circle_outlined,
              color: selected ? KonaColors.ink : KonaColors.muted,
            ),
          ],
        ),
      ),
    ),
  );
}
