import 'dart:async';

import 'package:flutter/widgets.dart';

import '../api/api_client.dart';
import '../config/app_config.dart';
import '../api/mobile_session_store.dart';
import '../data/inventory_repository.dart';
import '../models/inventory_models.dart';

class MobileRealtimeSync with WidgetsBindingObserver {
  MobileRealtimeSync({
    required this.repository,
    required this.session,
    required this.onChanged,
    required this.onRevoked,
  });

  final InventoryRepository repository;
  final MobileSessionStore session;
  final Future<void> Function(
    List<MobileSyncDelta> deltas,
    bool requiresBootstrap,
  )
  onChanged;
  final Future<void> Function() onRevoked;
  Timer? _timer;
  bool _running = false;
  String? _accessFingerprint;

  void start() {
    if (AppConfig.mockMode) return;
    WidgetsBinding.instance.addObserver(this);
    _resume();
  }

  void dispose() {
    _timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _resume();
    } else {
      _timer?.cancel();
      _timer = null;
    }
  }

  void _resume() {
    _timer?.cancel();
    unawaited(_poll());
    _timer = Timer.periodic(const Duration(seconds: 5), (_) => _poll());
  }

  Future<void> _poll() async {
    if (_running ||
        WidgetsBinding.instance.lifecycleState != AppLifecycleState.resumed) {
      return;
    }
    if (!await session.hasSession) return;

    _running = true;
    try {
      final deltas = <MobileSyncDelta>[];
      var changed = false;
      var fullResync = false;
      var accessChanged = false;
      var hasMore = false;
      do {
        final delta = await repository.sync();
        deltas.add(delta);
        changed = changed || delta.hasDataChanges;
        fullResync = fullResync || delta.fullResyncRequired;
        hasMore = delta.hasMore;
        if (_accessFingerprint == null) {
          _accessFingerprint = delta.accessFingerprint;
        } else if (_accessFingerprint != delta.accessFingerprint) {
          _accessFingerprint = delta.accessFingerprint;
          accessChanged = true;
        }
      } while (hasMore);

      if (changed || fullResync || accessChanged) {
        await onChanged(deltas, fullResync || accessChanged);
      }
    } on ApiFailure catch (error) {
      if ({
        'mobile_disabled',
        'mobile_access_denied',
        'mobile_access_revoked',
        'device_revoked',
        'upgrade_required',
        'refresh_reuse_detected',
      }.contains(error.code)) {
        await session.clear();
        await onRevoked();
      }
    } catch (_) {
      // A transient poll failure must never interrupt active field work.
    } finally {
      _running = false;
    }
  }
}
