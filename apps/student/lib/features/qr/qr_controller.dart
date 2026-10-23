import 'dart:async';

import 'package:civora_core/civora_core.dart';
import 'package:flutter/foundation.dart';

typedef QrTickerFactory = Timer Function(void Function() onTick);

class QrController extends ChangeNotifier {
  QrController({
    required this.api,
    Duration Function()? elapsed,
    QrTickerFactory? createTicker,
  }) : _createTicker =
           createTicker ??
           ((callback) =>
               Timer.periodic(const Duration(seconds: 1), (_) => callback())) {
    _stopwatch.start();
    _elapsed = elapsed ?? (() => _stopwatch.elapsed);
  }

  final ApiClient api;
  late final Duration Function() _elapsed;
  final QrTickerFactory _createTicker;
  final Stopwatch _stopwatch = Stopwatch();
  Timer? _ticker;
  QrDto? _qr;
  Duration? _deadline;
  String? _error;
  bool _foreground = true;
  bool _loading = false;
  bool _disposed = false;
  int _generation = 0;

  QrDto? get qr => qrVisibleAsActive ? _qr : null;
  String? get error => _error;
  bool get isLoading => _loading;
  Duration get remaining {
    final deadline = _deadline;
    if (!_foreground || deadline == null) return Duration.zero;
    final difference = deadline - _elapsed();
    return difference.isNegative ? Duration.zero : difference;
  }

  bool get qrVisibleAsActive =>
      !_disposed && _foreground && _qr != null && remaining > Duration.zero;

  Future<QrDto> refresh() async {
    if (_disposed) throw StateError('QR controller has been disposed.');
    if (!_foreground) {
      throw StateError('QR hanya dapat dimuat saat aplikasi aktif.');
    }

    final generation = ++_generation;
    final startedAt = _elapsed();
    _ticker?.cancel();
    _qr = null;
    _deadline = null;
    _error = null;
    _loading = true;
    notifyListeners();

    try {
      final data = await api.post('/student/qr', const {});
      final qr = QrDto.fromJson(data);
      if (generation == _generation && _foreground && !_disposed) {
        _qr = qr;
        _deadline = startedAt + qr.expiresAt.difference(qr.serverTime);
        if (!qrVisibleAsActive) {
          _qr = null;
          _deadline = null;
          _error = 'QR kedaluwarsa sebelum diterima. Coba lagi.';
        } else {
          _ticker = _createTicker(_onTick);
        }
      }
      return qr;
    } catch (error) {
      if (generation == _generation && !_disposed) {
        _qr = null;
        _deadline = null;
        _error = 'QR tidak dapat dimuat. Periksa koneksi, lalu coba lagi.';
      }
      rethrow;
    } finally {
      if (generation == _generation && !_disposed) {
        _loading = false;
        notifyListeners();
      }
    }
  }

  void setForeground(bool active) {
    if (_disposed || _foreground == active) return;
    _foreground = active;
    ++_generation;
    _ticker?.cancel();
    _ticker = null;
    _qr = null;
    _deadline = null;
    _error = null;
    _loading = false;
    notifyListeners();
    if (active) unawaited(refresh().then<void>((_) {}, onError: (Object _) {}));
  }

  void _onTick() {
    if (!qrVisibleAsActive) {
      _qr = null;
      _deadline = null;
      _ticker?.cancel();
      _ticker = null;
      notifyListeners();
      unawaited(refresh().then<void>((_) {}, onError: (Object _) {}));
      return;
    }
    notifyListeners();
  }

  @override
  void dispose() {
    if (_disposed) return;
    _disposed = true;
    ++_generation;
    _ticker?.cancel();
    _ticker = null;
    _qr = null;
    _deadline = null;
    super.dispose();
  }
}
