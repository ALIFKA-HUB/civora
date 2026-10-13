import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';
import '../points/record_point_controller.dart';

class TeacherStudentDetailScreen extends StatefulWidget {
  const TeacherStudentDetailScreen({
    super.key,
    required this.api,
    required this.student,
    required this.onRecord,
  });
  final ApiClient api;
  final TeacherStudent student;
  final ValueChanged<TeacherStudent> onRecord;
  @override
  State<TeacherStudentDetailScreen> createState() =>
      _TeacherStudentDetailScreenState();
}

class _TeacherStudentDetailScreenState
    extends State<TeacherStudentDetailScreen> {
  Map<String, dynamic>? _profile;
  List<dynamic> _records = const [];
  String? _error;
  bool _loading = true;
  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Profil siswa')),
    body: _loading
        ? const Center(
            child: CircularProgressIndicator(
              semanticsLabel: 'Memuat profil siswa',
            ),
          )
        : _error != null
        ? Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(_error!),
                  TextButton(onPressed: _load, child: const Text('Coba lagi')),
                ],
              ),
            ),
          )
        : ListView(
            padding: const EdgeInsets.all(20),
            children: [
              Text(
                widget.student.name,
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              const SizedBox(height: 8),
              Text('NISN ${widget.student.nisn}'),
              const SizedBox(height: 4),
              Text(
                '${_profile?['enrollment']?['classroom']?['name'] ?? widget.student.classroom} · ${_profile?['enrollment']?['major']?['name'] ?? widget.student.major}',
              ),
              const SizedBox(height: 20),
              Text(
                'Semester ${_profile?['semester']?['name'] ?? 'aktif'}',
                style: Theme.of(context).textTheme.titleMedium,
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: _Total(
                      label: 'Prestasi',
                      value: _profile?['totals']?['achievement'],
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: _Total(
                      label: 'Pelanggaran',
                      value: _profile?['totals']?['violation'],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 20),
              FilledButton.icon(
                onPressed: () => widget.onRecord(widget.student),
                icon: const Icon(Icons.add),
                label: const Text('Catat poin'),
              ),
              const SizedBox(height: 24),
              Text(
                'Riwayat semester',
                style: Theme.of(context).textTheme.titleLarge,
              ),
              if (_records.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 20),
                  child: Text('Belum ada catatan pada semester ini.'),
                ),
              ..._records.map((row) {
                final record = row as Map<String, dynamic>;
                final revision =
                    record['latest_revision'] as Map<String, dynamic>? ??
                    const {};
                return ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(revision['name'] as String? ?? 'Catatan'),
                  subtitle: Text(
                    '${revision['category'] ?? '—'} · ${revision['description'] ?? ''}',
                  ),
                  trailing: Text('${revision['points'] ?? '—'} poin'),
                );
              }),
            ],
          ),
  );

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final values = await Future.wait([
        widget.api.get('/teacher/students/${widget.student.id}'),
        widget.api.get(
          '/teacher/students/${widget.student.id}/records',
          query: {'per_page': 20},
        ),
      ]);
      if (mounted) {
        setState(() {
          _profile = values[0];
          _records = values[1]['data'] as List<dynamic>? ?? const [];
        });
      }
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'Tidak dapat memuat profil siswa.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }
}

class _Total extends StatelessWidget {
  const _Total({required this.label, required this.value});
  final String label;
  final Object? value;
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(16),
    decoration: BoxDecoration(
      color: Theme.of(context).colorScheme.surfaceContainerHighest,
      borderRadius: BorderRadius.circular(16),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label),
        const SizedBox(height: 8),
        Text(
          value?.toString() ?? '—',
          style: Theme.of(context).textTheme.headlineMedium,
        ),
      ],
    ),
  );
}
