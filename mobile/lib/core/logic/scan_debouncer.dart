class ScanDebouncer {
  ScanDebouncer({this.window = const Duration(seconds: 2)});

  final Duration window;
  String? _lastCode;
  DateTime? _lastAcceptedAt;

  bool accept(String code, {DateTime? at}) {
    final value = code.trim();
    if (value.isEmpty) return false;

    final now = at ?? DateTime.now();
    final isDuplicate =
        value == _lastCode &&
        _lastAcceptedAt != null &&
        now.difference(_lastAcceptedAt!) < window;
    if (isDuplicate) return false;

    _lastCode = value;
    _lastAcceptedAt = now;
    return true;
  }

  void reset() {
    _lastCode = null;
    _lastAcceptedAt = null;
  }
}
