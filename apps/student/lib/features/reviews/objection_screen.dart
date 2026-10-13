import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';

class StudentObjectionScreen extends StatefulWidget {
  const StudentObjectionScreen({
    super.key,
    required this.api,
    required this.record,
  });

  final ApiClient api;
  final PointRecordDto record;

  @override
  State<StudentObjectionScreen> createState() => _StudentObjectionScreenState();
}

class _StudentObjectionScreenState extends State<StudentObjectionScreen> {
  final _formKey = GlobalKey<FormState>();
  final _reason = TextEditingController();
  String? _error;
  bool _submitting = false;
  bool _submitted = false;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _error = null;
      _submitting = true;
    });
    try {
      final review = ReviewDto.fromJson(
        await widget.api.post(
          '/student/records/${widget.record.id}/objections',
          {'reason': _reason.text.trim()},
        ),
      );
      if (!mounted) return;
      if (review.status != 'pending') {
        setState(
          () => _error =
              'Status permintaan belum dapat dipastikan. Muat detail catatan kembali.',
        );
        return;
      }
      setState(() => _submitted = true);
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } catch (_) {
      if (mounted) {
        setState(
          () => _error =
              'Keberatan belum dapat dikirim. Periksa koneksi lalu coba lagi.',
        );
      }
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Ajukan keberatan')),
    body: ListView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
      children: [
        Text(
          widget.record.latestRevision?['name'] as String? ?? 'Catatan poin',
          style: Theme.of(context).textTheme.titleLarge,
        ),
        const SizedBox(height: 8),
        const Text(
          'Jelaskan bagian catatan yang perlu diperiksa. Alasan harus berisi 5–1.000 karakter.',
        ),
        const SizedBox(height: 20),
        if (_submitted) ...[
          const Card(
            child: Padding(
              padding: EdgeInsets.all(16),
              child: Text(
                'Keberatan dikirim dan menunggu pemeriksaan. Poin tetap berlaku sampai admin memutuskan.',
              ),
            ),
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Kembali ke catatan'),
          ),
        ] else ...[
          Form(
            key: _formKey,
            child: TextFormField(
              controller: _reason,
              minLines: 4,
              maxLines: 8,
              maxLength: 1000,
              textCapitalization: TextCapitalization.sentences,
              decoration: const InputDecoration(
                labelText: 'Alasan keberatan',
                alignLabelWithHint: true,
              ),
              validator: (value) {
                final length = value?.trim().runes.length ?? 0;
                if (length < 5) return 'Masukkan sedikitnya 5 karakter.';
                if (length > 1000) return 'Alasan maksimal 1.000 karakter.';
                return null;
              },
            ),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
                semanticsLabel: 'Kesalahan: $_error',
              ),
            ),
          const Text(
            'Poin pada catatan tetap berlaku sampai admin menyetujui perubahan.',
          ),
          const SizedBox(height: 16),
          FilledButton.icon(
            onPressed: _submitting ? null : _submit,
            icon: _submitting
                ? const SizedBox.square(
                    dimension: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.send_outlined),
            label: Text(_submitting ? 'Mengirim…' : 'Kirim keberatan'),
          ),
        ],
      ],
    ),
  );
}
