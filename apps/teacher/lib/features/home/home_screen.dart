import 'dart:async';
import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';
import '../points/record_point_controller.dart';
import '../points/record_point_screen.dart';
import '../points/record_summary_screen.dart';
import '../profile/profile_screen.dart';
import '../records/teacher_records_screen.dart';
import '../scanner/scanner_screen.dart';
import '../students/student_detail_screen.dart';
import '../students/student_search_screen.dart';

class TeacherHomeScreen extends StatefulWidget {
  const TeacherHomeScreen({
    super.key,
    required this.api,
    required this.session,
    this.recordController,
  });
  final ApiClient api;
  final SessionController session;
  final RecordPointController? recordController;
  @override
  State<TeacherHomeScreen> createState() => _TeacherHomeScreenState();
}

class _TeacherHomeScreenState extends State<TeacherHomeScreen> {
  late final RecordPointController _recordController;
  int _tab = 0;
  List<dynamic> _recentRecords = const [];
  bool _loadingRecords = true;
  @override
  void initState() {
    super.initState();
    _recordController =
        widget.recordController ?? RecordPointController(widget.api);
    widget.session.addListener(_sessionChanged);
    _loadRecentRecords();
  }

  @override
  void dispose() {
    widget.session.removeListener(_sessionChanged);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Civora Guru')),
    body: Column(
      children: [
        if (_recordController.hasUnknownAttempt)
          MaterialBanner(
            content: const Text(
              'Hasil simpan catatan belum diketahui. Periksa status atau kirim ulang dengan kunci yang sama.',
            ),
            actions: [
              TextButton(
                onPressed: _openUnknownAttempt,
                child: const Text('Periksa upaya'),
              ),
            ],
          ),
        Expanded(
          child: IndexedStack(
            index: _tab,
            children: [
              _homeTab(),
              TeacherStudentSearchScreen(
                api: widget.api,
                onSelected: _openStudent,
              ),
              TeacherRecordsScreen(api: widget.api),
              TeacherProfileScreen(session: widget.session),
            ],
          ),
        ),
      ],
    ),
    bottomNavigationBar: NavigationBar(
      selectedIndex: _tab,
      onDestinationSelected: (index) => setState(() => _tab = index),
      destinations: const [
        NavigationDestination(
          icon: Icon(Icons.home_outlined),
          selectedIcon: Icon(Icons.home),
          label: 'Beranda',
        ),
        NavigationDestination(
          icon: Icon(Icons.people_outline),
          selectedIcon: Icon(Icons.people),
          label: 'Siswa',
        ),
        NavigationDestination(icon: Icon(Icons.history), label: 'Riwayat'),
        NavigationDestination(
          icon: Icon(Icons.person_outline),
          label: 'Profil',
        ),
      ],
    ),
  );

  Widget _homeTab() {
    final profile = widget.session.profile;
    return RefreshIndicator(
      onRefresh: _loadRecentRecords,
      child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            'Halo, ${profile?.account.name ?? 'Guru'}',
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: 4),
          Text('Semester ${profile?.currentSemester?.name ?? 'aktif'}'),
          const SizedBox(height: 24),
          FilledButton.icon(
            onPressed: _openScanner,
            icon: const Icon(Icons.qr_code_scanner),
            label: const Text('Scan QR'),
          ),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: () => setState(() => _tab = 1),
            icon: const Icon(Icons.search),
            label: const Text('Cari Siswa'),
          ),
          const SizedBox(height: 28),
          Text(
            'Catatan terbaru',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          if (_loadingRecords)
            const Padding(
              padding: EdgeInsets.all(20),
              child: Center(child: CircularProgressIndicator()),
            ),
          if (!_loadingRecords && _recentRecords.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 20),
              child: Text('Belum ada catatan dari akun ini.'),
            ),
          ..._recentRecords.take(5).map((row) {
            final record = row as Map<String, dynamic>;
            final student =
                record['student'] as Map<String, dynamic>? ?? const {};
            final revision =
                record['latest_revision'] as Map<String, dynamic>? ?? const {};
            return ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(student['name'] as String? ?? 'Siswa'),
              subtitle: Text(
                '${revision['name'] ?? 'Catatan'} · ${record['semester']?['name'] ?? '—'}',
              ),
              trailing: Text('${revision['points'] ?? '—'}'),
            );
          }),
        ],
      ),
    );
  }

  Future<void> _loadRecentRecords() async {
    try {
      final response = await widget.api.get(
        '/teacher/records',
        query: const {'per_page': 5},
      );
      if (mounted) {
        setState(() {
          _recentRecords = response['data'] as List<dynamic>? ?? const [];
          _loadingRecords = false;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loadingRecords = false);
    }
  }

  Future<void> _openScanner() async {
    var selected = false;
    var profileLoadFailed = false;
    await Navigator.push<void>(
      context,
      MaterialPageRoute<void>(
        builder: (_) => TeacherScannerScreen(
          onManualSearch: () {
            Navigator.pop(context);
            setState(() => _tab = 1);
          },
          onToken: (token, requestKey) async {
            try {
              await _recordController.selectQr(token, requestKey);
              selected = true;
            } on StudentProfileLoadException {
              profileLoadFailed = true;
            } on ApiException {
              rethrow;
            }
          },
        ),
      ),
    );
    if (!mounted) return;
    if (selected) await _openRecordForm();
    if (profileLoadFailed && mounted) _offerProfileRetry();
  }

  void _openStudent(TeacherStudent student) {
    Navigator.push(
      context,
      MaterialPageRoute<void>(
        builder: (_) => TeacherStudentDetailScreen(
          api: widget.api,
          student: student,
          onRecord: (selected) async {
            Navigator.pop(context);
            try {
              await _recordController.selectManual(selected.id);
              await _openRecordForm();
            } on StudentProfileLoadException {
              if (mounted) _offerProfileRetry();
            } on ApiException catch (error) {
              if (mounted) {
                ScaffoldMessenger.of(
                  context,
                ).showSnackBar(SnackBar(content: Text(error.message)));
              }
            } catch (_) {
              if (mounted) {
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(
                    content: Text('Tidak dapat memilih siswa. Coba lagi.'),
                  ),
                );
              }
            }
          },
        ),
      ),
    );
  }

  Future<void> _openRecordForm() async {
    await Navigator.push<void>(
      context,
      MaterialPageRoute<void>(
        builder: (_) => TeacherRecordPointScreen(
          api: widget.api,
          controller: _recordController,
          schoolTimezone: widget.session.profile?.schoolTimezone,
          onReselect: () {
            Navigator.pop(context);
            setState(() => _tab = 1);
          },
        ),
      ),
    );
    await _loadRecentRecords();
  }

  Future<void> _openUnknownAttempt() => Navigator.push<void>(
    context,
    MaterialPageRoute<void>(
      builder: (_) => RecordSummaryScreen(
        controller: _recordController,
        api: widget.api,
        schoolTimezone: widget.session.profile?.schoolTimezone,
      ),
    ),
  );

  void _offerProfileRetry() {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: const Text(
          'Pemilihan siswa tersimpan, tetapi identitas belum dapat dimuat.',
        ),
        action: SnackBarAction(
          label: 'Coba lagi',
          onPressed: () => unawaited(_retrySelectedProfile()),
        ),
      ),
    );
  }

  Future<void> _retrySelectedProfile() async {
    try {
      await _recordController.retryStudentProfile();
      if (mounted) await _openRecordForm();
    } on StudentProfileLoadException catch (error) {
      final detail = error.cause is ApiException
          ? (error.cause as ApiException).message
          : 'Periksa koneksi dan coba lagi.';
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(detail),
            action: SnackBarAction(
              label: 'Coba lagi',
              onPressed: () => unawaited(_retrySelectedProfile()),
            ),
          ),
        );
      }
    }
  }

  void _sessionChanged() {
    if (widget.session.state == SessionState.authenticated || !mounted) return;
    Navigator.of(context).popUntil((route) => route.isFirst);
  }
}
