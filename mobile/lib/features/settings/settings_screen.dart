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
        const _SessionSecurityCard(),
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

class _SessionSecurityCard extends ConsumerStatefulWidget {
  const _SessionSecurityCard();

  @override
  ConsumerState<_SessionSecurityCard> createState() =>
      _SessionSecurityCardState();
}

class _SessionSecurityCardState extends ConsumerState<_SessionSecurityCard> {
  bool _loading = true;
  bool _keepSignedIn = false;
  bool _biometric = false;
  bool _biometricAvailable = false;
  bool _messageIsError = false;
  String? _message;

  @override
  void initState() {
    super.initState();
    Future<void>.microtask(_load);
  }

  Future<void> _load() async {
    final store = ref.read(sessionStoreProvider);
    final authenticator = ref.read(biometricAuthenticatorProvider);
    final values = await Future.wait<bool>([
      store.keepSignedIn,
      store.biometricUnlock,
      authenticator.isAvailable,
    ]);
    if (!mounted) return;
    setState(() {
      _keepSignedIn = values[0];
      _biometric = values[1];
      _biometricAvailable = values[2];
      _loading = false;
    });
  }

  Future<void> _setKeepSignedIn(bool value) async {
    if (value) {
      final verified = await _confirmCurrentPassword();
      if (!verified || !mounted) return;
    }

    setState(() => _loading = true);
    try {
      await ref.read(sessionStoreProvider).setKeepSignedIn(value);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _messageIsError = true;
        _message = apiErrorMessage(
          error,
          fallback: 'Secure session storage could not be updated.',
        );
      });
      return;
    }
    if (!mounted) return;
    setState(() {
      _loading = false;
      _keepSignedIn = value;
      if (!value) _biometric = false;
      _messageIsError = false;
      _message = value
          ? 'Secure token storage enabled.'
          : 'This account will sign out when the app closes.';
    });
  }

  Future<bool> _confirmCurrentPassword() async {
    var password = '';
    var obscurePassword = true;
    var verifying = false;
    String? errorMessage;

    final verified = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (dialogContext, setDialogState) => AlertDialog(
          title: const Text('Confirm your password'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'Enter your current password before this device can keep your session. Your password is never stored.',
              ),
              const SizedBox(height: 16),
              TextField(
                key: const ValueKey('keep-signed-in-password'),
                autofocus: true,
                obscureText: obscurePassword,
                textInputAction: TextInputAction.done,
                autofillHints: const [AutofillHints.password],
                onChanged: (value) => password = value,
                decoration: InputDecoration(
                  labelText: 'Current password',
                  prefixIcon: const Icon(Icons.lock_outline),
                  errorText: errorMessage,
                  suffixIcon: IconButton(
                    tooltip: obscurePassword
                        ? 'Show password'
                        : 'Hide password',
                    onPressed: verifying
                        ? null
                        : () => setDialogState(
                            () => obscurePassword = !obscurePassword,
                          ),
                    icon: Icon(
                      obscurePassword
                          ? Icons.visibility_outlined
                          : Icons.visibility_off_outlined,
                    ),
                  ),
                ),
                onSubmitted: verifying
                    ? null
                    : (_) => _verifyDialogPassword(
                        dialogContext,
                        setDialogState,
                        password,
                        onLoading: (value) => verifying = value,
                        onError: (value) => errorMessage = value,
                      ),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: verifying
                  ? null
                  : () => Navigator.of(dialogContext).pop(false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              key: const ValueKey('confirm-keep-signed-in'),
              onPressed: verifying
                  ? null
                  : () => _verifyDialogPassword(
                      dialogContext,
                      setDialogState,
                      password,
                      onLoading: (value) => verifying = value,
                      onError: (value) => errorMessage = value,
                    ),
              child: verifying
                  ? const SizedBox.square(
                      dimension: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Text('Confirm'),
            ),
          ],
        ),
      ),
    );

    return verified == true;
  }

  Future<void> _verifyDialogPassword(
    BuildContext dialogContext,
    StateSetter setDialogState,
    String password, {
    required ValueChanged<bool> onLoading,
    required ValueChanged<String?> onError,
  }) async {
    if (password.isEmpty) {
      setDialogState(() => onError('Enter your current password.'));
      return;
    }

    setDialogState(() {
      onLoading(true);
      onError(null);
    });
    try {
      await ref.read(inventoryRepositoryProvider).verifyPassword(password);
      if (dialogContext.mounted) {
        Navigator.of(dialogContext).pop(true);
      }
    } catch (error) {
      if (!dialogContext.mounted) return;
      setDialogState(() {
        onLoading(false);
        onError(apiErrorMessage(error));
      });
    }
  }

  Future<void> _setBiometric(bool value) async {
    if (value && !_keepSignedIn) {
      setState(() => _message = 'Enable Keep me signed in first.');
      return;
    }
    if (value) {
      final accepted = await ref
          .read(biometricAuthenticatorProvider)
          .authenticate(reason: 'Enable biometric unlock for Inventory KONA');
      if (!accepted) {
        if (mounted) {
          setState(() => _message = 'Biometric setup was cancelled.');
        }
        return;
      }
    }
    await ref.read(sessionStoreProvider).setBiometricUnlock(value);
    if (!mounted) return;
    setState(() {
      _biometric = value;
      _messageIsError = false;
      _message = value
          ? 'Biometric unlock enabled.'
          : 'Biometric unlock disabled.';
    });
  }

  @override
  Widget build(BuildContext context) => KonaSectionCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SectionHeading(eyebrow: 'Security', title: 'Sign-in preferences'),
        const SizedBox(height: 10),
        SwitchListTile.adaptive(
          contentPadding: EdgeInsets.zero,
          value: _keepSignedIn,
          onChanged: _loading ? null : _setKeepSignedIn,
          title: const Text('Keep me signed in'),
          subtitle: const Text('Stores rotating tokens, never your password.'),
        ),
        SwitchListTile.adaptive(
          contentPadding: EdgeInsets.zero,
          value: _biometric,
          onChanged: _loading || !_biometricAvailable ? null : _setBiometric,
          title: const Text('Biometric unlock'),
          subtitle: Text(
            _biometricAvailable
                ? 'Require fingerprint or face unlock on cold start.'
                : 'Biometrics are not available on this device.',
          ),
        ),
        if (_message != null)
          Text(
            _message!,
            style: TextStyle(
              color: _messageIsError
                  ? Theme.of(context).colorScheme.error
                  : KonaColors.goldDark,
            ),
          ),
      ],
    ),
  );
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
