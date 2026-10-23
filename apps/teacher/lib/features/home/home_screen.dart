import 'dart:async';
import 'package:civora_core/civora_core.dart';
import 'package:civora_ui/civora_ui.dart';
import 'package:flutter/material.dart';
import '../points/record_point_controller.dart';
import '../points/record_point_screen.dart';
import '../points/record_summary_screen.dart';
import '../profile/profile_screen.dart';
import '../notifications/notifications_screen.dart';
import '../records/teacher_records_screen.dart';
import '../scanner/scanner_screen.dart';
import '../students/student_detail_screen.dart';
import '../students/student_search_screen.dart';

class TeacherHomeScreen extends StatefulWidget {
  const TeacherHomeScreen({
    super.key,
    required this.api,
    required this.session,
    required this.notifications,
    required this.themePreference,
    this.recordController,
  });
  final ApiClient api;
  final SessionController session;
  final NotificationController notifications;
  final ThemePreferenceController themePreference;
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
    appBar: AppBar(
      title: const Text('Civora Guru'),
      actions: [
        IconButton(
          tooltip: 'Notifikasi',
          icon: const Icon(Icons.notifications_outlined),
          onPressed: () => Navigator.of(context).push<void>(
            MaterialPageRoute(
              builder: (_) => TeacherNotificationsScreen(
                controller: widget.notifications,
                onOpenRecord: (id) => Navigator.of(context).push<void>(
                  MaterialPageRoute(
                    builder: (_) => TeacherRecordDetailScreen(
                      api: widget.api,
                      recordId: id,
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ],
    ),
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
              TeacherProfileScreen(
                session: widget.session,
                themePreference: widget.themePreference,
              ),
            ],
          ),
        ),
      ],
    ),
    bottomNavigationBar: CivoraCapsuleNavigationBar(
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
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Theme.of(context).colorScheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(24),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(
                      Icons.center_focus_weak,
                      size: 56,
                      color: Theme.of(context).colorScheme.onSurface,
                      semanticLabel: 'Pemindai QR',
                    ),
                    Container(
                      width: 1,
                      height: 64,
                      margin: const EdgeInsets.symmetric(horizontal: 16),
                      color: Theme.of(context).colorScheme.outlineVariant,
                    ),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Catat poin siswa',
                            style: Theme.of(context).textTheme.titleLarge,
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'Mulai dengan memindai QR siswa.',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 16),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton(
                    onPressed: _openScanner,
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const Text('Scan QR'),
                        const SizedBox(width: 12),
                        Container(
                          width: 36,
                          height: 36,
                          decoration: const BoxDecoration(
                            color: Color(0x332E3439),
                            shape: BoxShape.circle,
                          ),
                          child: const Icon(
                            Icons.camera_alt_outlined,
                            size: 20,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          SizedBox(
            width: double.infinity,
            child: OutlinedButton.icon(
              onPressed: () => setState(() => _tab = 1),
              icon: const Icon(Icons.search),
              label: const Align(
                alignment: Alignment.centerLeft,
                child: Text('Cari siswa secara manual'),
              ),
            ),
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
          session: widget.session,
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
