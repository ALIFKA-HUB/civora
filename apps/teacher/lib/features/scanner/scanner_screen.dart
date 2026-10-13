import 'dart:async';
import 'dart:math';
import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

class TeacherScannerScreen extends StatefulWidget {
  const TeacherScannerScreen({
    super.key,
    required this.onToken,
    required this.onManualSearch,
    this.cameraViewBuilder,
  });
  final Future<void> Function(String token, String requestKey) onToken;
  final VoidCallback onManualSearch;
  final WidgetBuilder? cameraViewBuilder;
  @override
  State<TeacherScannerScreen> createState() => _TeacherScannerScreenState();
}

class _TeacherScannerScreenState extends State<TeacherScannerScreen> {
  late final MobileScannerController _scanner;
  bool _handling = false;
  bool _cameraError = false;
  String? _lastToken;
  String? _lastRequestKey;
  String? _message;
  @override
  void initState() {
    super.initState();
    _scanner = MobileScannerController(
      formats: const [BarcodeFormat.qrCode],
      detectionSpeed: DetectionSpeed.noDuplicates,
    );
  }

  @override
  void dispose() {
    unawaited(_scanner.dispose());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Scan QR siswa')),
    body: ListView(
      padding: const EdgeInsets.all(20),
      children: [
        SizedBox(
          height: 360,
          child: ClipRRect(
            borderRadius: BorderRadius.circular(16),
            child:
                widget.cameraViewBuilder?.call(context) ??
                MobileScanner(
                  controller: _scanner,
                  onDetect: _onDetect,
                  onDetectError: (_, _) => _cameraFailed(),
                  errorBuilder: (context, error) {
                    WidgetsBinding.instance.addPostFrameCallback(
                      (_) => _cameraFailed(),
                    );
                    return const Center(
                      child: Text(
                        'Kamera tidak tersedia. Cari siswa secara manual.',
                      ),
                    );
                  },
                ),
          ),
        ),
        const SizedBox(height: 16),
        Text(
          _cameraError
              ? 'Izin kamera ditolak atau kamera tidak tersedia.'
              : 'Arahkan kamera ke QR Civora siswa.',
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.titleMedium,
        ),
        if (_message != null)
          Padding(
            padding: const EdgeInsets.only(top: 12),
            child: Text(
              _message!,
              textAlign: TextAlign.center,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ),
        const SizedBox(height: 16),
        FilledButton.icon(
          onPressed: widget.onManualSearch,
          icon: const Icon(Icons.search),
          label: const Text('Cari siswa secara manual'),
        ),
      ],
    ),
  );

  void _cameraFailed() {
    if (!mounted || _cameraError) return;
    setState(() => _cameraError = true);
  }

  Future<void> _onDetect(BarcodeCapture capture) async {
    if (_handling) return;
    final token = capture.barcodes
        .map((code) => code.rawValue)
        .whereType<String>()
        .firstOrNull;
    if (token == null ||
        !RegExp(r'^civora1_[A-Za-z0-9_-]{43}$').hasMatch(token)) {
      return;
    }
    setState(() {
      _handling = true;
      _message = null;
    });
    await _scanner.stop();
    try {
      if (_lastToken != token) {
        _lastToken = token;
        _lastRequestKey = _requestKey();
      }
      await widget.onToken(token, _lastRequestKey!);
      if (mounted) Navigator.pop(context);
    } catch (_) {
      if (mounted) {
        setState(() {
          _handling = false;
          _message =
              'QR tidak dapat digunakan. Minta siswa menampilkan QR terbaru atau cari siswa manual.';
        });
      }
      await _scanner.start();
    }
  }

  String _requestKey() {
    final bytes = List<int>.generate(16, (_) => Random.secure().nextInt(256));
    bytes[6] = bytes[6] & 0x0f | 0x40;
    bytes[8] = bytes[8] & 0x3f | 0x80;
    final hex = bytes
        .map((value) => value.toRadixString(16).padLeft(2, '0'))
        .join();
    return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(12, 16)}-${hex.substring(16, 20)}-${hex.substring(20)}';
  }
}
