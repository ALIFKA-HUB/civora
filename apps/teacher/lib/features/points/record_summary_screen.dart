import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';
import 'record_point_controller.dart';

class RecordSummaryScreen extends StatefulWidget {
  const RecordSummaryScreen({
    super.key,
    required this.controller,
    required this.api,
    this.schoolTimezone,
  });
  final RecordPointController controller;
  final ApiClient api;
  final String? schoolTimezone;
  @override
  State<RecordSummaryScreen> createState() => _RecordSummaryScreenState();
}

class _RecordSummaryScreenState extends State<RecordSummaryScreen> {
  bool _saving = false;
  bool _checking = false;
  String? _error;
  bool get _saved => widget.controller.savedRecord != null;

  @override
  Widget build(BuildContext context) {
    final c = widget.controller;
    final student = c.studentProfile ?? const <String, dynamic>{};
    final type = c.selectedPointType;
    final profileName = student['name'] as String? ?? 'Siswa';
    final classroom =
        (student['enrollment']
            as Map<String, dynamic>?)?['classroom']?['name'] ??
        '—';
    final record = c.savedRecord;
    return Scaffold(
      appBar: AppBar(
        title: Text(_saved ? 'Catatan tersimpan' : 'Ringkasan pencatatan'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          if (_saved) ...[
            const Icon(Icons.check_circle_outline, size: 56),
            const SizedBox(height: 12),
            Text(
              'Poin berhasil dicatat',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 8),
            Text('ID ${record!.id}', textAlign: TextAlign.center),
            Text(
              formatSchoolDate(record.occurredAt, widget.schoolTimezone) ??
                  'Waktu server tersedia',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 20),
            Text(
              'Total semester ${c.currentSemesterName ?? 'aktif'}',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 12),
            Text('Prestasi: ${c.currentTotalAchievement ?? '—'} poin'),
            Text('Pelanggaran: ${c.currentTotalViolation ?? '—'} poin'),
            const SizedBox(height: 20),
            FilledButton(
              onPressed: () =>
                  Navigator.popUntil(context, (route) => route.isFirst),
              child: const Text('Selesai'),
            ),
            OutlinedButton(
              onPressed: () {
                Navigator.popUntil(context, (route) => route.isFirst);
              },
              child: const Text('Kembali ke beranda'),
            ),
          ] else ...[
            Text(profileName, style: Theme.of(context).textTheme.titleLarge),
            Text('NISN ${student['nisn'] ?? '—'} · $classroom'),
            const Divider(height: 32),
            _Row(
              label: 'Semester',
              value: c.currentSemesterName ?? 'Belum tersedia',
            ),
            _Row(label: 'Jenis poin', value: type?.name ?? '—'),
            _Row(
              label: 'Kategori',
              value: type?.category == 'achievement'
                  ? 'Prestasi'
                  : type?.category == 'violation'
                  ? 'Pelanggaran'
                  : '—',
            ),
            _Row(
              label: 'Nilai',
              value: type == null ? '—' : '${type.points} poin',
            ),
            _Row(label: 'Keterangan', value: c.description.trim()),
            _Row(label: 'Bukti', value: c.photoName ?? 'Tidak ada'),
            if (c.selectionExpired)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Text(
                  'Sesi pemilihan siswa kedaluwarsa. Pilih siswa kembali.',
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Text(
                  _error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
            if (c.requiresReconfirmation) ...[
              const SizedBox(height: 12),
              const Text(
                'Data berubah. Keterangan tetap tersimpan; kembali ke formulir untuk meninjau jenis poin dan ringkasan terbaru.',
              ),
              const SizedBox(height: 8),
              OutlinedButton(
                onPressed: () => Navigator.pop(context),
                child: const Text('Kembali ke formulir'),
              ),
              if (c.requiresStudentReselection)
                TextButton(
                  onPressed: () =>
                      Navigator.popUntil(context, (route) => route.isFirst),
                  child: const Text('Pilih siswa kembali'),
                ),
            ] else if (c.hasUnknownAttempt) ...[
              const SizedBox(height: 12),
              const Text(
                'Hasil simpan belum diketahui. Periksa status atau kirim ulang dengan kunci yang sama sebelum membuat catatan baru.',
              ),
              const SizedBox(height: 8),
              OutlinedButton(
                onPressed: _checking ? null : _resolve,
                child: Text(_checking ? 'Memeriksa…' : 'Periksa status simpan'),
              ),
              OutlinedButton(
                onPressed: _saving ? null : _retry,
                child: Text(
                  _saving ? 'Mengirim…' : 'Kirim ulang dengan kunci yang sama',
                ),
              ),
            ] else ...[
              const SizedBox(height: 20),
              FilledButton(
                onPressed: _saving || c.selectionExpired ? null : _submit,
                child: Text(_saving ? 'Menyimpan…' : 'Simpan poin'),
              ),
            ],
          ],
        ],
      ),
    );
  }

  Future<void> _submit() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await widget.controller.submit();
    } on ApiException catch (error) {
      if (mounted) {
        setState(
          () => _error = widget.controller.requiresReconfirmation
              ? '${error.message} Periksa ulang ringkasan dan konfirmasikan lagi.'
              : error.message,
        );
      }
    } catch (_) {
      if (mounted) {
        setState(
          () => _error =
              'Koneksi terputus. Hasil simpan belum diketahui; periksa status sebelum mencoba lagi.',
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _resolve() async {
    setState(() {
      _checking = true;
      _error = null;
    });
    try {
      final result = await widget.controller.resolveUnknownAttempt();
      if (mounted && result == null) {
        setState(
          () => _error =
              'Server belum menunjukkan catatan. Upaya tetap dikunci; ulangi dengan kunci yang sama.',
        );
      }
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } catch (_) {
      if (mounted) {
        setState(
          () => _error = 'Belum dapat memeriksa status. Upaya tetap dikunci.',
        );
      }
    } finally {
      if (mounted) setState(() => _checking = false);
    }
  }

  Future<void> _retry() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await widget.controller.retryUnknownAttempt();
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } catch (_) {
      if (mounted) {
        setState(
          () => _error =
              'Hasil masih belum diketahui. Gunakan pemeriksaan status atau ulangi dengan kunci yang sama.',
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.label, required this.value});
  final String label;
  final String value;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 14),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.labelLarge),
        const SizedBox(height: 4),
        Text(value),
      ],
    ),
  );
}
