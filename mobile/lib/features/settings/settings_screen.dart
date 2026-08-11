import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/config/app_config.dart';
import '../../core/data/providers.dart';
import '../../core/theme/kona_theme.dart';
import '../../core/widgets/kona_page.dart';

class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  Future<void> _logout(BuildContext context, WidgetRef ref) async {
    await ref.read(inventoryRepositoryProvider).logout();
    ref.invalidate(bootstrapProvider);
    if (context.mounted) context.go('/login');
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final bootstrap = ref.watch(bootstrapProvider);
    return KonaPage(
      eyebrow: 'This device',
      title: 'Settings',
      description:
          'Mobile permissions and storage access are controlled by the owner on the website.',
      bottomAction: OutlinedButton.icon(
        onPressed: () => _logout(context, ref),
        icon: const Icon(Icons.logout),
        label: const Text('Sign out securely'),
      ),
      children: [
        bootstrap.when(
          loading: () => const KonaSectionCard(
            child: Center(child: CircularProgressIndicator()),
          ),
          error: (error, _) =>
              KonaSectionCard(child: Text(apiErrorMessage(error))),
          data: (data) => KonaSectionCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const SectionHeading(
                  eyebrow: 'Account',
                  title: 'Employee access',
                ),
                const SizedBox(height: 14),
                _SettingRow(
                  icon: Icons.person_outline,
                  label: 'Signed in as',
                  value: data.userName,
                ),
                _SettingRow(
                  icon: Icons.warehouse_outlined,
                  label: 'Default storage',
                  value: data.defaultStorage?.name ?? 'Not assigned',
                ),
                _SettingRow(
                  icon: Icons.inventory_2_outlined,
                  label: 'Assigned storages',
                  value: '${data.storages.length}',
                ),
                _SettingRow(
                  icon: Icons.security_outlined,
                  label: 'Capabilities',
                  value: data.capabilities.isEmpty
                      ? 'Read only'
                      : data.capabilities.join(', '),
                ),
              ],
            ),
          ),
        ),
        KonaSectionCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SectionHeading(
                eyebrow: 'Application',
                title: 'Build information',
              ),
              const SizedBox(height: 14),
              const _SettingRow(
                icon: Icons.info_outline,
                label: 'Version',
                value: AppConfig.appVersion,
              ),
              _SettingRow(
                icon: Icons.cloud_outlined,
                label: 'Data mode',
                value: AppConfig.mockMode ? 'Safe mock prototype' : 'Live API',
              ),
              _SettingRow(
                icon: Icons.link,
                label: 'API',
                value: AppConfig.mockMode
                    ? 'Not connected'
                    : AppConfig.apiBaseUrl,
              ),
            ],
          ),
        ),
        const KonaSectionCard(
          color: Color(0xFFFFF4CF),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                Icons.admin_panel_settings_outlined,
                color: KonaColors.goldDark,
              ),
              SizedBox(width: 10),
              Expanded(
                child: Text(
                  'To change storage access, direct restock, transfer, handover, or custody permissions, ask the owner to update Mobile Access on the website.',
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _SettingRow extends StatelessWidget {
  const _SettingRow({
    required this.icon,
    required this.label,
    required this.value,
  });

  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 9),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, color: KonaColors.goldDark, size: 21),
        const SizedBox(width: 11),
        Expanded(
          child: Text(label, style: const TextStyle(color: KonaColors.muted)),
        ),
        const SizedBox(width: 12),
        Flexible(
          child: Text(
            value,
            textAlign: TextAlign.right,
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
        ),
      ],
    ),
  );
}
