import 'dart:async';
import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';
import '../points/record_point_controller.dart';

class TeacherStudentSearchScreen extends StatefulWidget {
  const TeacherStudentSearchScreen({
    super.key,
    required this.api,
    required this.onSelected,
  });
  final ApiClient api;
  final ValueChanged<TeacherStudent> onSelected;
  @override
  State<TeacherStudentSearchScreen> createState() =>
      _TeacherStudentSearchScreenState();
}

class _TeacherStudentSearchScreenState
    extends State<TeacherStudentSearchScreen> {
  final _query = TextEditingController();
  Timer? _debounce;
  int _searchGeneration = 0;
  List<TeacherStudent> _students = const [];
  List<Map<String, dynamic>> _classrooms = const [];
  List<Map<String, dynamic>> _majors = const [];
  String? _classroomId;
  String? _majorId;
  bool _loading = false;
  String? _error;
  @override
  void initState() {
    super.initState();
    _search();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _query.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Cari siswa')),
    body: Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: TextField(
            controller: _query,
            autofocus: true,
            textInputAction: TextInputAction.search,
            onChanged: (_) => _queryChanged(),
            onSubmitted: (_) => _search(),
            decoration: InputDecoration(
              labelText: 'Nama atau NISN',
              prefixIcon: const Icon(Icons.search),
              suffixIcon: IconButton(
                tooltip: 'Hapus pencarian',
                onPressed: () {
                  _query.clear();
                  _search();
                },
                icon: const Icon(Icons.clear),
              ),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Row(
            children: [
              Expanded(
                child: DropdownButtonFormField<String?>(
                  initialValue: _classroomId,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'Kelas'),
                  items: [
                    const DropdownMenuItem(
                      value: null,
                      child: Text('Semua kelas'),
                    ),
                    ..._classrooms.map(
                      (item) => DropdownMenuItem(
                        value: item['id'] as String,
                        child: Text(item['name'] as String),
                      ),
                    ),
                  ],
                  onChanged: (value) {
                    setState(() => _classroomId = value);
                    _search();
                  },
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: DropdownButtonFormField<String?>(
                  initialValue: _majorId,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'Jurusan'),
                  items: [
                    const DropdownMenuItem(
                      value: null,
                      child: Text('Semua jurusan'),
                    ),
                    ..._majors.map(
                      (item) => DropdownMenuItem(
                        value: item['id'] as String,
                        child: Text(item['name'] as String),
                      ),
                    ),
                  ],
                  onChanged: (value) {
                    setState(() => _majorId = value);
                    _search();
                  },
                ),
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
                const SizedBox(height: 8),
                Center(
                  child: TextButton(
                    onPressed: _search,
                    child: const Text('Coba lagi'),
                  ),
                ),
              ],
            ),
          ),
        if (!_loading && _error == null && _students.isEmpty)
          const Expanded(
            child: Center(child: Text('Tidak ada siswa yang cocok.')),
          ),
        if (_students.isNotEmpty)
          Expanded(
            child: ListView.separated(
              itemCount: _students.length,
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
              separatorBuilder: (_, _) => const SizedBox(height: 8),
              itemBuilder: (context, index) {
                final student = _students[index];
                return Card(
                  child: Semantics(
                    button: true,
                    label: student.accessibleLabel,
                    child: ListTile(
                      title: Text(student.name),
                      subtitle: Text(
                        'NISN ${student.nisn} · ${student.classroom}${student.major.isEmpty ? '' : ' · ${student.major}'}',
                      ),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => widget.onSelected(student),
                    ),
                  ),
                );
              },
            ),
          ),
      ],
    ),
  );

  void _queryChanged() {
    _debounce?.cancel();
    final generation = ++_searchGeneration;
    setState(() {
      _loading = true;
      _error = null;
      _students = const [];
    });
    _debounce = Timer(
      const Duration(milliseconds: 300),
      () => _search(generation: generation),
    );
  }

  Future<void> _search({int? generation}) async {
    _debounce?.cancel();
    final requestGeneration = generation ?? ++_searchGeneration;
    if (requestGeneration != _searchGeneration) return;
    final query = _query.text.trim();
    final classroomId = _classroomId;
    final majorId = _majorId;
    setState(() {
      _loading = true;
      _error = null;
      _students = const [];
    });
    try {
      final response = await widget.api.get(
        '/teacher/students',
        query: {
          'q': query,
          'classroom_id': classroomId,
          'major_id': majorId,
          'per_page': 50,
        },
      );
      final results = response['data'] as List<dynamic>? ?? const [];
      final meta = response['meta'] as Map<String, dynamic>? ?? const {};
      final filters = meta['filters'] as Map<String, dynamic>? ?? const {};
      if (mounted && requestGeneration == _searchGeneration) {
        setState(() {
          _students = results
              .map(
                (row) => TeacherStudent.fromJson(row as Map<String, dynamic>),
              )
              .toList();
          _classrooms = (filters['classrooms'] as List<dynamic>? ?? const [])
              .cast<Map<String, dynamic>>();
          _majors = (filters['majors'] as List<dynamic>? ?? const [])
              .cast<Map<String, dynamic>>();
        });
      }
    } on ApiException catch (error) {
      if (mounted && requestGeneration == _searchGeneration) {
        setState(() => _error = error.message);
      }
    } catch (_) {
      if (mounted && requestGeneration == _searchGeneration) {
        setState(
          () => _error =
              'Tidak dapat memuat siswa. Periksa koneksi lalu coba lagi.',
        );
      }
    } finally {
      if (mounted && requestGeneration == _searchGeneration) {
        setState(() => _loading = false);
      }
    }
  }
}
