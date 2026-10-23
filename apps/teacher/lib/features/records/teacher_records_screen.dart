import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';
import '../reviews/correction_screen.dart';

class TeacherRecordsScreen extends StatefulWidget {
  const TeacherRecordsScreen({super.key, required this.api});
  final ApiClient api;
  @override
  State<TeacherRecordsScreen> createState() => _TeacherRecordsScreenState();
}

class _TeacherRecordsScreenState extends State<TeacherRecordsScreen> {
  List<dynamic> _records = const [];
  List<dynamic> _semesters = const [];
  String? _semesterId;
  String? _category;
  String? _status;
  bool _loading = true;
  String? _error;
  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const Text('Riwayat guru'),
      actions: [
        IconButton(
          tooltip: 'Muat ulang',
          onPressed: _load,
          icon: const Icon(Icons.refresh),
        ),
      ],
    ),
    body: Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            children: [
              DropdownButtonFormField<String?>(
                initialValue: _semesterId,
                decoration: const InputDecoration(labelText: 'Semester'),
                items: [
                  const DropdownMenuItem(
                    value: null,
                    child: Text('Semua semester'),
                  ),
                  ..._semesters.map((row) {
                    final item = row as Map<String, dynamic>;
                    return DropdownMenuItem(
                      value: item['id'] as String,
                      child: Text(item['name'] as String),
                    );
                  }),
                ],
                onChanged: (value) {
                  _semesterId = value;
                  _load();
                },
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    child: DropdownButtonFormField<String?>(
                      initialValue: _category,
                      decoration: const InputDecoration(labelText: 'Kategori'),
                      items: const [
                        DropdownMenuItem(value: null, child: Text('Semua')),
                        DropdownMenuItem(
                          value: 'achievement',
                          child: Text('Prestasi'),
                        ),
                        DropdownMenuItem(
                          value: 'violation',
                          child: Text('Pelanggaran'),
                        ),
                      ],
                      onChanged: (value) {
                        _category = value;
                        _load();
                      },
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: DropdownButtonFormField<String?>(
                      initialValue: _status,
                      decoration: const InputDecoration(labelText: 'Status'),
                      items: const [
                        DropdownMenuItem(value: null, child: Text('Semua')),
                        DropdownMenuItem(
                          value: 'active',
                          child: Text('Berlaku'),
                        ),
                        DropdownMenuItem(
                          value: 'cancelled',
                          child: Text('Dibatalkan'),
                        ),
                      ],
                      onChanged: (value) {
                        _status = value;
                        _load();
                      },
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
        if (_loading) const LinearProgressIndicator(),
        if (_error != null)
          Expanded(
            child: ListView(
              padding: const EdgeInsets.all(24),
              children: [
                Text(_error!, textAlign: TextAlign.center),
                Center(
                  child: TextButton(
                    onPressed: _load,
                    child: const Text('Coba lagi'),
                  ),
                ),
              ],
            ),
          ),
        if (!_loading && _error == null && _records.isEmpty)
          const Expanded(
            child: Center(child: Text('Belum ada catatan yang sesuai.')),
          ),
        if (_records.isNotEmpty)
          Expanded(
            child: ListView.separated(
              itemCount: _records.length,
              separatorBuilder: (_, _) => const Divider(height: 1),
              itemBuilder: (context, index) {
                final row = _records[index] as Map<String, dynamic>;
                final revision =
                    row['latest_revision'] as Map<String, dynamic>? ?? const {};
                final student =
                    row['student'] as Map<String, dynamic>? ?? const {};
                return Card(
                  child: ListTile(
                    title: Text(student['name'] as String? ?? 'Siswa'),
                    subtitle: Text(
                      '${revision['name'] ?? 'Catatan'} · ${row['semester']?['name'] ?? '—'} · ${revision['status'] == 'cancelled' ? 'Dibatalkan' : 'Berlaku'}',
                    ),
                    trailing: Text('${revision['points'] ?? '—'} poin'),
                    onTap: () => Navigator.push(
                      context,
                      MaterialPageRoute<void>(
                        builder: (_) => TeacherRecordDetailScreen(
                          api: widget.api,
                          recordId: row['id'] as String,
                        ),
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
      ],
    ),
  );

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final query = <String, dynamic>{
        'per_page': 50,
        if (_semesterId != null) 'semester_id': _semesterId,
        if (_category != null) 'category': _category,
        if (_status != null) 'status': _status,
      };
      final response = await widget.api.get('/teacher/records', query: query);
      final meta = response['meta'] as Map<String, dynamic>? ?? const {};
      if (mounted) {
        setState(() {
          _records = response['data'] as List<dynamic>? ?? const [];
          _semesters =
              meta['available_semesters'] as List<dynamic>? ?? const [];
        });
      }
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'Tidak dapat memuat riwayat.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }
}

class TeacherRecordDetailScreen extends StatefulWidget {
  const TeacherRecordDetailScreen({
    super.key,
    required this.api,
    required this.recordId,
  });
  final ApiClient api;
  final String recordId;
  @override
  State<TeacherRecordDetailScreen> createState() =>
      _TeacherRecordDetailScreenState();
}

class _TeacherRecordDetailScreenState extends State<TeacherRecordDetailScreen> {
  Map<String, dynamic>? _record;
  String? _error;
  bool _loading = true;
  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final record = _record ?? const <String, dynamic>{};
    final revision =
        record['latest_revision'] as Map<String, dynamic>? ?? const {};
    return Scaffold(
      appBar: AppBar(title: const Text('Detail catatan')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
          ? ListView(
              padding: const EdgeInsets.all(24),
              children: [
                Text(_error!, textAlign: TextAlign.center),
                Center(
                  child: TextButton(
                    onPressed: _load,
                    child: const Text('Coba lagi'),
                  ),
                ),
              ],
            )
          : ListView(
              padding: const EdgeInsets.all(20),
              children: [
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          record['student']?['name'] as String? ?? 'Siswa',
                          style: Theme.of(context).textTheme.headlineSmall,
                        ),
                        Text(
                          '${record['semester']?['name'] ?? '—'} · ${record['source'] == 'qr' ? 'QR' : 'Manual'}',
                        ),
                        const Divider(height: 32),
                        Text(
                          revision['name'] as String? ?? 'Catatan',
                          style: Theme.of(context).textTheme.titleLarge,
                        ),
                        Text(
                          '${revision['category'] == 'achievement' ? 'Prestasi' : 'Pelanggaran'} · ${revision['points'] ?? '—'} poin',
                        ),
                        const SizedBox(height: 12),
                        Text(
                          revision['description'] as String? ??
                              'Tanpa keterangan',
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                Text(
                  'Status: ${revision['status'] == 'cancelled' ? 'Dibatalkan' : 'Berlaku'}',
                ),
                if (record['review_status'] == 'pending')
                  const Padding(
                    padding: EdgeInsets.only(top: 12),
                    child: Text(
                      'Permintaan pemeriksaan sedang menunggu keputusan. Total belum berubah.',
                    ),
                  ),
                const SizedBox(height: 24),
                OutlinedButton.icon(
                  onPressed: () => Navigator.push(
                    context,
                    MaterialPageRoute<void>(
                      builder: (_) => TeacherCorrectionScreen(
                        api: widget.api,
                        record: record,
                      ),
                    ),
                  ),
                  icon: const Icon(Icons.edit_note),
                  label: const Text('Minta koreksi'),
                ),
              ],
            ),
    );
  }

  Future<void> _load() async {
    try {
      final value = await widget.api.get('/teacher/records/${widget.recordId}');
      if (mounted) setState(() => _record = value);
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'Tidak dapat memuat catatan.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }
}
