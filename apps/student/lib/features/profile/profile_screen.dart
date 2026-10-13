import 'package:civora_core/civora_core.dart';
import 'package:civora_ui/civora_ui.dart';
import 'package:flutter/material.dart';

class StudentProfileScreen extends StatefulWidget {
  const StudentProfileScreen({super.key, required this.session});
  final SessionController session;

  @override
  State<StudentProfileScreen> createState() => _StudentProfileScreenState();
}

class _StudentProfileScreenState extends State<StudentProfileScreen> {
  String? _error;
  String? _notice;

  @override
  Widget build(BuildContext context) {
    final profile = widget.session.profile;
    return Scaffold(
      appBar: AppBar(title: const Text('Profil siswa')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          _ProfileValue(label: 'Nama', value: profile?.account.name ?? '—'),
          _ProfileValue(label: 'NISN', value: profile?.student?.nisn ?? '—'),
          _ProfileValue(
            label: 'Semester aktif',
            value: profile?.currentSemester?.name ?? 'Belum tersedia',
          ),
          _ProfileValue(
            label: 'Kelas',
            value:
                profile?.ownEnrollment?.classroom.name ??
                'Belum ada penempatan aktif',
          ),
          const SizedBox(height: 24),
          Text('Ganti password', style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 16),
          PasswordForm(
            isBusy: widget.session.isBusy,
            errorMessage: _error,
            onSubmit: _changePassword,
          ),
          if (_notice != null)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text(_notice!),
            ),
          const SizedBox(height: 16),
          OutlinedButton.icon(
            onPressed: widget.session.isBusy ? null : _logout,
            icon: const Icon(Icons.logout),
            label: const Text('Keluar'),
          ),
        ],
      ),
    );
  }

  Future<void> _changePassword(String current, String next) async {
    setState(() {
      _error = null;
      _notice = null;
    });
    try {
      await widget.session.changePassword(current, next);
      if (mounted) {
        setState(
          () => _notice =
              'Password berhasil diganti. Masuk kembali dengan password baru.',
        );
      }
    } on ApiException catch (error) {
      if (mounted) {
        setState(() => _error = error.message);
      }
    } catch (_) {
      if (mounted) {
        setState(
          () => _error =
              'Tidak dapat terhubung. Periksa koneksi internet lalu coba lagi.',
        );
      }
    }
  }

  Future<void> _logout() async {
    try {
      await widget.session.logout();
    } on ApiException catch (error) {
      if (mounted) {
        setState(() => _error = error.message);
      }
    } catch (_) {
      if (mounted) {
        setState(
          () => _error =
              'Sesi dihapus dari perangkat. Koneksi server tidak tersedia.',
        );
      }
    }
  }
}

class _ProfileValue extends StatelessWidget {
  const _ProfileValue({required this.label, required this.value});
  final String label;
  final String value;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 16),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.bodySmall),
        const SizedBox(height: 4),
        Text(value, style: Theme.of(context).textTheme.bodyLarge),
      ],
    ),
  );
}
