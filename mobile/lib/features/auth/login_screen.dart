import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/config/app_config.dart';
import '../../core/data/providers.dart';
import '../../core/theme/kona_theme.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _email = TextEditingController(
    text: AppConfig.mockMode ? 'staff@kona.local' : '',
  );
  final _password = TextEditingController(
    text: AppConfig.mockMode ? 'mock-password' : '',
  );
  bool _loading = false;
  bool _initializing = true;
  bool _obscurePassword = true;
  bool _keepSignedIn = true;
  bool _biometricSession = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    Future<void>.microtask(_restoreSession);
  }

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      await ref
          .read(inventoryRepositoryProvider)
          .login(
            _email.text.trim(),
            _password.text,
            keepSignedIn: _keepSignedIn,
          );
      ref.read(mobileSessionRevokedProvider.notifier).state = false;
      ref.invalidate(bootstrapProvider);
      if (mounted) context.go('/home');
    } catch (error) {
      if (mounted) {
        setState(
          () => _error = apiErrorMessage(
            error,
            fallback: 'Sign-in failed. Check your details and try again.',
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _restoreSession({bool forceBiometric = false}) async {
    if (AppConfig.mockMode) {
      if (mounted) setState(() => _initializing = false);
      return;
    }

    final store = ref.read(sessionStoreProvider);
    final email = await store.savedEmail;
    final hasSession = await store.hasSession;
    final keepSignedIn = await store.keepSignedIn;
    final biometricUnlock = await store.biometricUnlock;
    if (email != null && email.isNotEmpty) _email.text = email;

    if (!hasSession || !keepSignedIn) {
      if (hasSession && !keepSignedIn) await store.clear();
      if (mounted) setState(() => _initializing = false);
      return;
    }

    if (biometricUnlock || forceBiometric) {
      final authenticator = ref.read(biometricAuthenticatorProvider);
      if (!await authenticator.isAvailable) {
        await store.setBiometricUnlock(false);
        if (mounted) {
          setState(() {
            _initializing = false;
            _biometricSession = false;
            _error =
                'Biometric unlock is no longer available. Sign in with your password.';
          });
        }
        return;
      }
      final authenticated = await authenticator.authenticate(
        reason: 'Unlock Inventory KONA',
      );
      if (!authenticated) {
        if (mounted) {
          setState(() {
            _initializing = false;
            _biometricSession = true;
          });
        }
        return;
      }
    }

    try {
      await ref.read(inventoryRepositoryProvider).bootstrap();
      ref.invalidate(bootstrapProvider);
      if (mounted) context.go('/home');
    } catch (_) {
      await store.clear();
      if (mounted) {
        setState(() {
          _initializing = false;
          _biometricSession = false;
          _error = 'Your saved session expired. Sign in again.';
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    body: SafeArea(
      child: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 430),
            child: Card(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(28, 32, 28, 28),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Image.asset(
                      'assets/brand/kona-logo.png',
                      height: 82,
                      fit: BoxFit.contain,
                    ),
                    const SizedBox(height: 28),
                    Text(
                      'Inventory access',
                      style: Theme.of(context).textTheme.headlineMedium,
                    ),
                    const SizedBox(height: 8),
                    const Text(
                      'Sign in with the employee account created on the website.',
                      style: TextStyle(color: KonaColors.muted),
                    ),
                    const SizedBox(height: 24),
                    TextField(
                      controller: _email,
                      keyboardType: TextInputType.emailAddress,
                      textInputAction: TextInputAction.next,
                      decoration: const InputDecoration(
                        labelText: 'Email',
                        prefixIcon: Icon(Icons.mail_outline),
                      ),
                    ),
                    const SizedBox(height: 13),
                    TextField(
                      controller: _password,
                      obscureText: _obscurePassword,
                      onSubmitted: (_) => _login(),
                      decoration: InputDecoration(
                        labelText: 'Password',
                        prefixIcon: const Icon(Icons.lock_outline),
                        suffixIcon: IconButton(
                          key: const Key('password-visibility'),
                          tooltip: _obscurePassword
                              ? 'Show password'
                              : 'Hide password',
                          onPressed: () => setState(
                            () => _obscurePassword = !_obscurePassword,
                          ),
                          icon: Icon(
                            _obscurePassword
                                ? Icons.visibility_outlined
                                : Icons.visibility_off_outlined,
                          ),
                        ),
                      ),
                    ),
                    CheckboxListTile(
                      key: const Key('keep-signed-in'),
                      contentPadding: EdgeInsets.zero,
                      controlAffinity: ListTileControlAffinity.leading,
                      value: _keepSignedIn,
                      onChanged: _loading || _initializing
                          ? null
                          : (value) =>
                                setState(() => _keepSignedIn = value ?? false),
                      title: const Text(
                        'Keep me signed in',
                        style: TextStyle(fontWeight: FontWeight.w700),
                      ),
                      subtitle: const Text(
                        'Stores a secure session token, never your password.',
                      ),
                    ),
                    if (_error != null) ...[
                      const SizedBox(height: 12),
                      Container(
                        key: const Key('login-error'),
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: KonaColors.danger.withValues(alpha: 0.08),
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(
                            color: KonaColors.danger.withValues(alpha: 0.24),
                          ),
                        ),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Icon(
                              Icons.error_outline,
                              color: KonaColors.danger,
                              size: 20,
                            ),
                            const SizedBox(width: 9),
                            Expanded(
                              child: Text(
                                _error!,
                                style: const TextStyle(
                                  color: KonaColors.danger,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                    const SizedBox(height: 20),
                    ElevatedButton.icon(
                      onPressed: _loading || _initializing ? null : _login,
                      icon: _loading
                          ? const SizedBox.square(
                              dimension: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.arrow_forward),
                      label: Text(
                        _initializing
                            ? 'Checking session'
                            : _loading
                            ? 'Signing in'
                            : 'Sign in',
                      ),
                    ),
                    if (_biometricSession) ...[
                      const SizedBox(height: 12),
                      OutlinedButton.icon(
                        key: const Key('biometric-unlock'),
                        onPressed: _loading
                            ? null
                            : () => _restoreSession(forceBiometric: true),
                        icon: const Icon(Icons.fingerprint),
                        label: const Text('Unlock with biometrics'),
                      ),
                    ],
                    if (AppConfig.mockMode) ...[
                      const SizedBox(height: 16),
                      const Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(
                            Icons.science_outlined,
                            size: 17,
                            color: KonaColors.goldDark,
                          ),
                          SizedBox(width: 6),
                          Flexible(
                            child: Text(
                              'Mock mode · no production data',
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                color: KonaColors.goldDark,
                                fontSize: 12,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    ),
  );
}
