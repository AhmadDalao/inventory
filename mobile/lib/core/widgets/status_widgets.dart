import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../data/providers.dart';
import '../models/inventory_models.dart';
import '../theme/kona_theme.dart';

typedef MobileAccessPredicate = bool Function(MobileBootstrap bootstrap);

class MobileAccessGate extends ConsumerWidget {
  const MobileAccessGate({
    super.key,
    required this.child,
    required this.allow,
    this.message = 'This page is not enabled for your account.',
  });

  final Widget child;
  final MobileAccessPredicate allow;
  final String message;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ref.watch(bootstrapProvider).when(
      loading: () => const ColoredBox(
        color: KonaColors.canvas,
        child: Center(child: CircularProgressIndicator()),
      ),
      error: (error, _) => ColoredBox(
        color: KonaColors.canvas,
        child: SafeArea(
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: EmptyState(
                icon: Icons.cloud_off_outlined,
                title: 'Access could not be verified',
                message: apiErrorMessage(error),
              ),
            ),
          ),
        ),
      ),
      data: (bootstrap) => allow(bootstrap)
          ? child
          : ColoredBox(
              color: KonaColors.canvas,
              child: SafeArea(
                child: Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: AccessDeniedState(message: message),
                  ),
                ),
              ),
            ),
    );
  }
}

class StatusPill extends StatelessWidget {
  const StatusPill({
    super.key,
    required this.label,
    this.tone = StatusTone.neutral,
  });

  final String label;
  final StatusTone tone;

  @override
  Widget build(BuildContext context) {
    final colors = switch (tone) {
      StatusTone.success => (const Color(0xFFDDF6E9), KonaColors.success),
      StatusTone.warning => (const Color(0xFFFFF0C7), const Color(0xFF8A5A00)),
      StatusTone.danger => (const Color(0xFFFFE2DE), KonaColors.danger),
      StatusTone.info => (const Color(0xFFDCEBF7), KonaColors.info),
      StatusTone.neutral => (KonaColors.soft, KonaColors.ink),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
      decoration: BoxDecoration(
        color: colors.$1,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: colors.$2,
          fontSize: 12,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

enum StatusTone { neutral, success, warning, danger, info }

class AccessDeniedState extends StatelessWidget {
  const AccessDeniedState({
    super.key,
    this.message = 'This action is not enabled for your account.',
  });

  final String message;

  @override
  Widget build(BuildContext context) => EmptyState(
    icon: Icons.lock_outline,
    title: 'Access restricted',
    message: message,
  );
}

class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.icon,
    required this.title,
    required this.message,
  });

  final IconData icon;
  final String title;
  final String message;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 32),
    child: Column(
      children: [
        Icon(icon, size: 40, color: KonaColors.goldDark),
        const SizedBox(height: 10),
        Text(title, style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 5),
        Text(
          message,
          textAlign: TextAlign.center,
          style: const TextStyle(color: KonaColors.muted),
        ),
      ],
    ),
  );
}
