import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

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
  String? _error;

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
          .login(_email.text.trim(), _password.text);
      ref.invalidate(bootstrapProvider);
      if (mounted) context.go('/home');
    } catch (error) {
      if (mounted) setState(() => _error = error.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
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
                      obscureText: true,
                      onSubmitted: (_) => _login(),
                      decoration: const InputDecoration(
                        labelText: 'Password',
                        prefixIcon: Icon(Icons.lock_outline),
                      ),
                    ),
                    if (_error != null) ...[
                      const SizedBox(height: 12),
                      Text(
                        _error!,
                        style: const TextStyle(color: KonaColors.danger),
                      ),
                    ],
                    const SizedBox(height: 20),
                    ElevatedButton.icon(
                      onPressed: _loading ? null : _login,
                      icon: _loading
                          ? const SizedBox.square(
                              dimension: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.arrow_forward),
                      label: Text(_loading ? 'Signing in' : 'Sign in'),
                    ),
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
