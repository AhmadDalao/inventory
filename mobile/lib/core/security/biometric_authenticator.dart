import 'package:local_auth/local_auth.dart';

abstract class BiometricAuthenticator {
  Future<bool> get isAvailable;
  Future<bool> authenticate({required String reason});
}

class DeviceBiometricAuthenticator implements BiometricAuthenticator {
  DeviceBiometricAuthenticator([LocalAuthentication? authentication])
    : _authentication = authentication ?? LocalAuthentication();

  final LocalAuthentication _authentication;

  @override
  Future<bool> get isAvailable async {
    try {
      if (!await _authentication.isDeviceSupported()) return false;
      if (!await _authentication.canCheckBiometrics) return false;
      return (await _authentication.getAvailableBiometrics()).isNotEmpty;
    } catch (_) {
      return false;
    }
  }

  @override
  Future<bool> authenticate({required String reason}) async {
    try {
      return await _authentication.authenticate(
        localizedReason: reason,
        biometricOnly: true,
        persistAcrossBackgrounding: true,
      );
    } catch (_) {
      return false;
    }
  }
}
