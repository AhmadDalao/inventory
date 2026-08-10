import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../core/config/app_config.dart';
import '../../core/logic/scan_debouncer.dart';
import '../../core/theme/kona_theme.dart';

class ScannerScreen extends StatefulWidget {
  const ScannerScreen({super.key, required this.mode});

  final String mode;

  @override
  State<ScannerScreen> createState() => _ScannerScreenState();
}

class _ScannerScreenState extends State<ScannerScreen> {
  final _scanDebouncer = ScanDebouncer();

  void _detected(String code) {
    if (!_scanDebouncer.accept(code)) return;
    context.pop(code);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: KonaColors.ink,
    appBar: AppBar(
      backgroundColor: KonaColors.ink,
      foregroundColor: Colors.white,
      title: Text(widget.mode == 'reference' ? 'Scan reference' : 'Scan item'),
    ),
    body: SafeArea(
      child: Column(
        children: [
          Expanded(
            child: Padding(
              padding: const EdgeInsets.all(18),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(28),
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    if (AppConfig.mockMode)
                      Container(
                        color: const Color(0xFF24231F),
                        child: const Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(
                              Icons.qr_code_scanner,
                              color: KonaColors.gold,
                              size: 88,
                            ),
                            SizedBox(height: 14),
                            Text(
                              'Mock camera view',
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 20,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            Text(
                              'Tap a sample below to simulate a scan.',
                              style: TextStyle(color: Colors.white60),
                            ),
                          ],
                        ),
                      )
                    else
                      MobileScanner(
                        onDetect: (capture) {
                          final value = capture.barcodes.firstOrNull?.rawValue;
                          if (value != null && value.isNotEmpty) {
                            _detected(value);
                          }
                        },
                      ),
                    Center(
                      child: Container(
                        width: 245,
                        height: 170,
                        decoration: BoxDecoration(
                          border: Border.all(color: KonaColors.gold, width: 3),
                          borderRadius: BorderRadius.circular(24),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 22),
            decoration: const BoxDecoration(
              color: KonaColors.surface,
              borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  kIsWeb
                      ? 'Camera access depends on browser permission.'
                      : 'Hold the barcode inside the gold frame.',
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: KonaColors.muted),
                ),
                if (AppConfig.mockMode) ...[
                  const SizedBox(height: 14),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          onPressed: () => _detected('62811001'),
                          child: const Text('Blue wristband'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: OutlinedButton(
                          onPressed: () => _detected('HDO-20260810-7A21'),
                          child: const Text('Handover ref'),
                        ),
                      ),
                    ],
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    ),
  );
}
