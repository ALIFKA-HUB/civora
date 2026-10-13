import 'package:civora_core/civora_core.dart';
import 'package:civora_ui/civora_ui.dart';
import 'package:flutter/material.dart';

class StudentAuthScreen extends StatefulWidget {
  const StudentAuthScreen({
    super.key,
    required this.session,
    required this.api,
    this.activation = false,
  });
  final SessionController session;
  final ApiClient api;
  final bool activation;

  @override
  State<StudentAuthScreen> createState() => _StudentAuthScreenState();
}

class _StudentAuthScreenState extends State<StudentAuthScreen> {
  String? _error;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(
        widget.activation ? 'Aktivasi akun siswa' : 'Masuk sebagai siswa',
      ),
    ),
    body: Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 480),
        child: ListView(
          padding: const EdgeInsets.all(24),
          shrinkWrap: true,
          children: [
            const Icon(Icons.school_outlined, size: 44),
            const SizedBox(height: 16),
            Text(
              widget.activation ? 'Buat password baru' : 'Civora Siswa',
              style: Theme.of(context).textTheme.headlineSmall,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 24),
            if (widget.activation)
              PasswordForm(
                activation: true,
                isBusy: widget.session.isBusy,
                errorMessage: _error,
                onSubmit: _changePassword,
              )
            else
              LoginForm(
                identifierLabel: 'NISN',
                isBusy: widget.session.isBusy,
                errorMessage: _error,
                restoreMessage: widget.session.restoreError == null
                    ? null
                    : 'Sesi belum dapat dipulihkan. Periksa koneksi lalu masuk kembali.',
                infoMessage: widget.session.noticeMessage,
                onSubmit: _login,
              ),
          ],
        ),
      ),
    ),
  );

  Future<void> _login(String identifier, String password) async {
    setState(() => _error = null);
    try {
      await widget.session.login(identifier, password, role: 'student');
    } on ApiException catch (error) {
      if (mounted) {
        setState(() => _error = error.message);
      }
    } on SessionRoleException {
      if (mounted) {
        setState(
          () => _error =
              'Akun ini tidak sesuai dengan aplikasi siswa. Hubungi admin sekolah.',
        );
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

  Future<void> _changePassword(String current, String next) async {
    setState(() => _error = null);
    try {
      await widget.session.changePassword(current, next);
    } on ApiException catch (error) {
      if (mounted) {
        setState(() => _error = error.message);
      }
    } on PasswordLengthException {
      if (mounted) {
        setState(
          () => _error = 'Password baru harus 12 sampai 1.024 karakter.',
        );
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
}
