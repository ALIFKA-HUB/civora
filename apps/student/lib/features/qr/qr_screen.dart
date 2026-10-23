import 'dart:async';

import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';
import 'package:qr_flutter/qr_flutter.dart';

import 'qr_controller.dart';

class StudentQrScreen extends StatefulWidget {
  const StudentQrScreen({
    super.key,
    required this.api,
    required this.studentName,
    required this.nisn,
    required this.classLabel,
  });
  final ApiClient api;
  final String studentName;
  final String nisn;
  final String classLabel;

  @override
  State<StudentQrScreen> createState() => _StudentQrScreenState();
}

class _StudentQrScreenState extends State<StudentQrScreen>
    with WidgetsBindingObserver {
  late final QrController _controller;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _controller = QrController(api: widget.api);
    unawaited(_controller.refresh().then<void>((_) {}, onError: (Object _) {}));
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _controller.setForeground(state == AppLifecycleState.resumed);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: _controller,
    builder: (context, _) {
      final qr = _controller.qr;
      final seconds =
          _controller.remaining.inSeconds +
          (_controller.remaining.inMilliseconds % 1000 == 0 ? 0 : 1);
      return Scaffold(
        appBar: AppBar(title: const Text('QR kehadiran')),
        body: ListView(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
          children: [
            Text(
              'Tunjukkan kode ini kepada guru untuk memilih akunmu.',
              style: Theme.of(context).textTheme.bodyLarge,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 20),
            Text(
              widget.studentName,
              style: Theme.of(context).textTheme.titleLarge,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 4),
            Text(
              'NISN ${widget.nisn} · ${widget.classLabel}',
              style: Theme.of(context).textTheme.bodyMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 20),
            Center(
              child: Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Theme.of(context).colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(24),
                ),
                child: Builder(
                  builder: (context) {
                    final qrSize = (MediaQuery.sizeOf(context).width - 112)
                        .clamp(180.0, 256.0);
                    return Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: qr == null
                          ? SizedBox(
                              width: qrSize,
                              height: qrSize,
                              child: Center(
                                child: _controller.isLoading
                                    ? const CircularProgressIndicator(
                                        semanticsLabel: 'Memuat QR',
                                      )
                                    : const Icon(
                                        Icons.qr_code_2,
                                        size: 72,
                                        color: Colors.black,
                                      ),
                              ),
                            )
                          : QrImageView(
                              data: qr.token,
                              size: qrSize,
                              backgroundColor: Colors.white,
                              eyeStyle: const QrEyeStyle(color: Colors.black),
                              dataModuleStyle: const QrDataModuleStyle(
                                color: Colors.black,
                              ),
                              errorCorrectionLevel: QrErrorCorrectLevel.M,
                              semanticsLabel: 'QR Civora untuk pemilihan siswa',
                            ),
                    );
                  },
                ),
              ),
            ),
            const SizedBox(height: 16),
            Semantics(
              label: 'Status koneksi',
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(
                    qr != null ? Icons.wifi : Icons.wifi_off,
                    size: 20,
                    semanticLabel: qr != null
                        ? 'Terhubung ke server'
                        : 'Tidak terhubung ke server',
                  ),
                  const SizedBox(width: 8),
                  Text(
                    qr != null
                        ? 'Terhubung'
                        : _controller.isLoading
                        ? 'Menghubungkan'
                        : 'Tidak terhubung',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                ],
              ),
            ),
            const SizedBox(height: 8),
            if (qr != null)
              Semantics(
                label: 'Sisa waktu QR',
                liveRegion: false,
                child: Text(
                  '$seconds detik',
                  style: Theme.of(context).textTheme.titleLarge,
                  textAlign: TextAlign.center,
                ),
              )
            else
              Text(
                _controller.error ?? 'QR tidak aktif saat aplikasi di latar.',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodyLarge,
              ),
            const SizedBox(height: 8),
            Text(
              qr == null
                  ? 'Kode lama tidak dapat digunakan. Muat kode baru saat online.'
                  : 'Kode hanya berlaku sementara dan berganti otomatis.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 20),
            FilledButton.icon(
              onPressed: _controller.isLoading
                  ? null
                  : () => unawaited(
                      _controller.refresh().then<void>(
                        (_) {},
                        onError: (Object _) {},
                      ),
                    ),
              icon: const Icon(Icons.refresh),
              label: const Text('Muat QR baru'),
            ),
          ],
        ),
      );
    },
  );
}
