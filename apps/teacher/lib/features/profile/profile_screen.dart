import 'package:civora_core/civora_core.dart';
import 'package:civora_ui/civora_ui.dart';
import 'package:flutter/material.dart';

class TeacherProfileScreen extends StatefulWidget {
  const TeacherProfileScreen({
    super.key,
    required this.session,
    required this.themePreference,
  });
  final SessionController session;
  final ThemePreferenceController themePreference;

  @override
  State<TeacherProfileScreen> createState() => _TeacherProfileScreenState();
}

class _TeacherProfileScreenState extends State<TeacherProfileScreen> {
  String? _error;
  bool _leaving = false;

  @override
  void initState() {
    super.initState();
    widget.session.addListener(_sessionChanged);
  }

  void _sessionChanged() {
    if (!mounted ||
        _leaving ||
        widget.session.state == SessionState.authenticated) {
      return;
    }
    _leaving = true;
    Navigator.of(context).popUntil((route) => route.isFirst);
  }

  @override
  void dispose() {
    widget.session.removeListener(_sessionChanged);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final profile = widget.session.profile;
    return Scaffold(
      appBar: AppBar(title: const Text('Profil guru')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _ProfileValue(
                    label: 'Nama',
                    value: profile?.account.name ?? '—',
                  ),
                  _ProfileValue(
                    label: 'NIP',
                    value: profile?.teacher?.nip ?? '—',
                  ),
                  _ProfileValue(
                    label: 'Kode guru',
                    value: profile?.teacher?.teacherCode ?? '—',
                  ),
                  _ProfileValue(
                    label: 'Semester aktif',
                    value: profile?.currentSemester?.name ?? 'Belum tersedia',
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 24),
          Text('Tampilan', style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 12),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: DropdownButtonFormField<ThemePreference>(
                initialValue: widget.themePreference.preference,
                decoration: const InputDecoration(labelText: 'Tema aplikasi'),
                items: const [
                  DropdownMenuItem(
                    value: ThemePreference.system,
                    child: Text('Ikuti perangkat'),
                  ),
                  DropdownMenuItem(
                    value: ThemePreference.light,
                    child: Text('Terang'),
                  ),
                  DropdownMenuItem(
                    value: ThemePreference.dark,
                    child: Text('Gelap'),
                  ),
                ],
                onChanged: (value) {
                  if (value != null) widget.themePreference.set(value);
                },
              ),
            ),
          ),
          const SizedBox(height: 24),
          Text('Ganti password', style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 16),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: PasswordForm(
                isBusy: widget.session.isBusy,
                errorMessage: _error,
                onSubmit: _changePassword,
              ),
            ),
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
    setState(() => _error = null);
    try {
      await widget.session.changePassword(current, next);
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
