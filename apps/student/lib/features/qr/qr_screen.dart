import 'dart:async';

import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';
import 'package:qr_flutter/qr_flutter.dart';

import 'qr_controller.dart';

class StudentQrScreen extends StatefulWidget {
  const StudentQrScreen({super.key, required this.api});
  final ApiClient api;

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
          padding: const EdgeInsets.all(20),
          children: [
            Text(
              'Tunjukkan kode ini kepada guru untuk memilih akunmu.',
              style: Theme.of(context).textTheme.bodyLarge,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 24),
            Center(
              child: Container(
                constraints: const BoxConstraints(
                  minWidth: 280,
                  minHeight: 280,
                ),
                padding: const EdgeInsets.all(12),
                color: Colors.white,
                child: qr == null
                    ? Center(
                        child: _controller.isLoading
                            ? const CircularProgressIndicator(
                                semanticsLabel: 'Memuat QR',
                              )
                            : const Icon(
                                Icons.qr_code_2,
                                size: 72,
                                color: Color(0xFF526866),
                              ),
                      )
                    : QrImageView(
                        data: qr.token,
                        size: 256,
                        backgroundColor: Colors.white,
                        eyeStyle: const QrEyeStyle(color: Colors.black),
                        dataModuleStyle: const QrDataModuleStyle(
                          color: Colors.black,
                        ),
                        errorCorrectionLevel: QrErrorCorrectLevel.M,
                        semanticsLabel: 'QR Civora untuk pemilihan siswa',
                      ),
              ),
            ),
            const SizedBox(height: 20),
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
