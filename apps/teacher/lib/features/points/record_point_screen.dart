import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'record_point_controller.dart';
import 'record_summary_screen.dart';

class TeacherRecordPointScreen extends StatefulWidget {
  const TeacherRecordPointScreen({
    super.key,
    required this.api,
    required this.controller,
    this.schoolTimezone,
    this.onReselect,
  });
  final ApiClient api;
  final RecordPointController controller;
  final String? schoolTimezone;
  final VoidCallback? onReselect;
  @override
  State<TeacherRecordPointScreen> createState() =>
      _TeacherRecordPointScreenState();
}

class _TeacherRecordPointScreenState extends State<TeacherRecordPointScreen> {
  final _description = TextEditingController();
  bool _loading = true;
  bool _uploading = false;
  String? _error;
  @override
  void initState() {
    super.initState();
    _description.text = widget.controller.description;
    _loadTypes();
  }

  @override
  void dispose() {
    _description.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final c = widget.controller;
    final profile = c.studentProfile ?? const <String, dynamic>{};
    final enrollment =
        profile['enrollment'] as Map<String, dynamic>? ?? const {};
    final classroom =
        enrollment['classroom'] as Map<String, dynamic>? ?? const {};
    final major = enrollment['major'] as Map<String, dynamic>? ?? const {};
    return Scaffold(
      appBar: AppBar(title: const Text('Catat poin')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.all(20),
              children: [
                if (c.hasUnknownAttempt)
                  MaterialBanner(
                    content: const Text(
                      'Hasil simpan belum diketahui. Detail ini dikunci sampai status terjawab.',
                    ),
                    actions: [
                      TextButton(
                        onPressed: _reviewAttempt,
                        child: const Text('Periksa upaya'),
                      ),
                    ],
                  ),
                if (c.requiresStudentReselection)
                  _Notice(
                    text: c.selectionExpired
                        ? 'Sesi pemilihan siswa berakhir. Pilih siswa kembali sebelum melanjutkan.'
                        : 'Semester atau penempatan berubah. Pilih siswa kembali untuk menggunakan data terbaru.',
                    color: Theme.of(context).colorScheme.error,
                  ),
                Text(
                  profile['name'] as String? ?? 'Siswa',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                Text(
                  'NISN ${profile['nisn'] ?? '—'} · ${classroom['name'] ?? '—'} · ${major['name'] ?? '—'}',
                ),
                const SizedBox(height: 20),
                DropdownButtonFormField<String>(
                  initialValue: c.selectedPointType?.versionId,
                  decoration: const InputDecoration(labelText: 'Jenis poin'),
                  items: c.pointTypes
                      .map(
                        (type) => DropdownMenuItem(
                          value: type.versionId,
                          child: Text('${type.name} · ${type.points} poin'),
                        ),
                      )
                      .toList(),
                  onChanged: c.isAttemptLocked
                      ? null
                      : (id) {
                          if (id != null) {
                            setState(() {
                              c.selectPointType(id);
                            });
                          }
                        },
                ),
                if (c.pointTypes.isEmpty)
                  const Padding(
                    padding: EdgeInsets.only(top: 8),
                    child: Text(
                      'Katalog poin belum tersedia. Coba muat ulang.',
                    ),
                  ),
                const SizedBox(height: 16),
                TextField(
                  controller: _description,
                  readOnly: c.isAttemptLocked,
                  minLines: 3,
                  maxLines: 7,
                  maxLength: 1000,
                  onChanged: (value) => c.description = value,
                  decoration: const InputDecoration(
                    labelText: 'Keterangan wajib',
                    hintText: 'Tuliskan alasan pencatatan secara jelas.',
                  ),
                ),
                const SizedBox(height: 4),
                Text('Bukti foto opsional (JPG/PNG, maksimal 5 MB).'),
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  onPressed: _uploading || c.isAttemptLocked
                      ? null
                      : _choosePhoto,
                  icon: const Icon(Icons.add_a_photo_outlined),
                  label: Text(
                    _uploading
                        ? 'Mengunggah…'
                        : c.photoName == null
                        ? 'Tambah foto'
                        : 'Ganti foto · ${c.photoName}',
                  ),
                ),
                if (c.photoName != null)
                  TextButton.icon(
                    onPressed: _uploading || c.isAttemptLocked
                        ? null
                        : () => setState(() {
                            c.removePhoto();
                          }),
                    icon: const Icon(Icons.delete_outline),
                    label: const Text('Hapus foto'),
                  ),
                if (_error != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 12),
                    child: _Notice(
                      text: _error!,
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                if (c.requiresReconfirmation)
                  Padding(
                    padding: EdgeInsets.only(top: 12),
                    child: _Notice(
                      text:
                          'Data semester atau katalog berubah. Periksa ringkasan dan pilih ulang jenis poin.',
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                const SizedBox(height: 20),
                FilledButton(
                  onPressed:
                      c.isAttemptLocked ||
                          c.selectionExpired ||
                          c.pointTypes.isEmpty
                      ? null
                      : _continue,
                  child: const Text('Lanjut ke ringkasan'),
                ),
                if (c.requiresStudentReselection)
                  TextButton(
                    onPressed: c.isAttemptLocked ? null : widget.onReselect,
                    child: const Text('Pilih siswa kembali'),
                  ),
              ],
            ),
    );
  }

  Future<void> _loadTypes() async {
    try {
      await widget.controller.loadPointTypes();
    } on ApiException catch (error) {
      _error = error.message;
    } catch (_) {
      _error = 'Tidak dapat memuat katalog poin.';
    }
    if (mounted) setState(() => _loading = false);
  }

  Future<void> _choosePhoto() async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      builder: (context) => SafeArea(
        child: Wrap(
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: const Text('Ambil foto'),
              onTap: () => Navigator.pop(context, ImageSource.camera),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Pilih dari galeri'),
              onTap: () => Navigator.pop(context, ImageSource.gallery),
            ),
          ],
        ),
      ),
    );
    if (source == null || !mounted) return;
    setState(() {
      _uploading = true;
      _error = null;
    });
    try {
      final file = await ImagePicker().pickImage(source: source);
      if (file == null) return;
      if (await file.length() > 5 * 1024 * 1024) {
        throw ArgumentError('Foto harus berukuran maksimal 5 MB.');
      }
      final bytes = await file.readAsBytes();
      final extension = file.name.toLowerCase().split('.').last;
      final mimeType = extension == 'jpg' || extension == 'jpeg'
          ? 'image/jpeg'
          : extension == 'png'
          ? 'image/png'
          : 'application/octet-stream';
      await widget.controller.uploadPhoto(
        bytes: bytes,
        filename: file.name,
        mimeType: mimeType,
      );
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } on ArgumentError catch (error) {
      if (mounted) setState(() => _error = error.message);
    } catch (_) {
      if (mounted) {
        setState(() => _error = 'Foto tidak dapat dipilih atau diunggah.');
      }
    } finally {
      if (mounted) setState(() => _uploading = false);
    }
  }

  Future<void> _continue() async {
    widget.controller.description = _description.text;
    if (widget.controller.requiresStudentReselection) {
      setState(
        () => _error = 'Sesi pemilihan siswa berakhir. Pilih siswa kembali.',
      );
      return;
    }
    if (widget.controller.selectedPointType == null ||
        _description.text.trim().runes.length < 5 ||
        _description.text.trim().runes.length > 1000) {
      setState(
        () =>
            _error = 'Pilih jenis poin dan tulis keterangan 5–1.000 karakter.',
      );
      return;
    }
    await Navigator.push(
      context,
      MaterialPageRoute<void>(
        builder: (_) => RecordSummaryScreen(
          controller: widget.controller,
          api: widget.api,
          schoolTimezone: widget.schoolTimezone,
        ),
      ),
    );
    if (mounted) {
      setState(() => _description.text = widget.controller.description);
    }
  }

  Future<void> _reviewAttempt() => Navigator.push<void>(
    context,
    MaterialPageRoute<void>(
      builder: (_) => RecordSummaryScreen(
        controller: widget.controller,
        api: widget.api,
        schoolTimezone: widget.schoolTimezone,
      ),
    ),
  );
}

class _Notice extends StatelessWidget {
  const _Notice({required this.text, required this.color});
  final String text;
  final Color color;
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: color.withValues(alpha: .1),
      borderRadius: BorderRadius.circular(12),
    ),
    child: Text(text, style: TextStyle(color: color)),
  );
}
