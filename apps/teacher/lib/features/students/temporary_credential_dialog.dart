import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';

class RevealedTemporaryCredential {
  const RevealedTemporaryCredential(this.password, this.expiresAt);
  final String password;
  final DateTime expiresAt;
}

class TeacherCredentialHelpController {
  TeacherCredentialHelpController({
    required this.api,
    required this.studentId,
    required this.accountStatus,
    required this.mustChangePassword,
  });

  final ApiClient api;
  final String studentId;
  final String accountStatus;
  final bool mustChangePassword;
  String? errorMessage;

  bool get canReveal =>
      accountStatus == 'pending_activation' && mustChangePassword;
  String get statusMessage => accountStatus == 'active' && !mustChangePassword
      ? 'Akun siswa sudah aktif. Password pribadi tidak dapat dilihat.'
      : 'Tidak ada password sementara yang dapat dibagikan.';

  Future<RevealedTemporaryCredential?> reveal(String currentPassword) async {
    if (!canReveal) return null;
    if (currentPassword.isEmpty) throw const PasswordRequiredException();
    errorMessage = null;
    try {
      final result = await api.post(
        '/teacher/students/$studentId/temporary-credential/reveal',
        {'current_password': currentPassword},
      );
      final password = result['password'];
      final expiry = result['expires_at'];
      if (password is! String || expiry is! String) return null;
      return RevealedTemporaryCredential(
        password,
        DateTime.parse(expiry).toUtc(),
      );
    } on ApiException catch (error) {
      errorMessage = error.statusCode == 404 || error.statusCode == 410
          ? 'Password sementara tidak tersedia atau sudah kedaluwarsa.'
          : error.message;
      rethrow;
    }
  }
}

class PasswordRequiredException implements Exception {
  const PasswordRequiredException();
}

class TemporaryCredentialDialog extends StatefulWidget {
  const TemporaryCredentialDialog({
    super.key,
    required this.controller,
    required this.session,
  });

  final TeacherCredentialHelpController controller;
  final SessionController session;

  @override
  State<TemporaryCredentialDialog> createState() =>
      _TemporaryCredentialDialogState();
}

class _TemporaryCredentialDialogState extends State<TemporaryCredentialDialog>
    with WidgetsBindingObserver {
  final _password = TextEditingController();
  RevealedTemporaryCredential? _credential;
  bool _busy = false;
  String? _error;
  int _visibilityGeneration = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    widget.session.addListener(_sessionChanged);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) _clearSensitive();
  }

  void _sessionChanged() {
    if (widget.session.state != SessionState.authenticated) {
      _clearSensitive();
      if (mounted) Navigator.of(context).pop();
    }
  }

  void _clearSensitive() {
    _visibilityGeneration++;
    _password.clear();
    if (mounted) setState(() => _credential = null);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    widget.session.removeListener(_sessionChanged);
    _password.clear();
    _password.dispose();
    _credential = null;
    super.dispose();
  }

  Future<void> _reveal() async {
    final visibilityGeneration = ++_visibilityGeneration;
    final sessionGeneration = widget.session.generation;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await widget.controller.reveal(_password.text);
      _password.clear();
      if (mounted &&
          visibilityGeneration == _visibilityGeneration &&
          sessionGeneration == widget.session.generation &&
          widget.session.state == SessionState.authenticated) {
        setState(() => _credential = result);
      }
    } on PasswordRequiredException {
      if (mounted) {
        setState(
          () => _error = 'Masukkan password akun guru untuk melanjutkan.',
        );
      }
    } on ApiException catch (error) {
      _password.clear();
      if (mounted) {
        setState(
          () => _error = widget.controller.errorMessage ?? error.message,
        );
      }
    } catch (_) {
      _password.clear();
      if (mounted) {
        setState(() => _error = 'Password sementara tidak dapat dimuat.');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Bantuan akun siswa'),
    content: widget.controller.canReveal
        ? Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (_credential == null) ...[
                const Text(
                  'Autentikasi ulang dengan password akun guru untuk menampilkan password sementara.',
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _password,
                  obscureText: true,
                  autofocus: true,
                  decoration: const InputDecoration(
                    labelText: 'Password guru saat ini',
                  ),
                  onSubmitted: (_) => _busy ? null : _reveal(),
                ),
                if (_error != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: Text(_error!),
                  ),
              ] else ...[
                const Text(
                  'Password sementara hanya berlaku sampai waktu berikut.',
                ),
                const SizedBox(height: 8),
                SelectableText(
                  _credential!.password,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 8),
                Text('Kedaluwarsa: ${_credential!.expiresAt.toLocal()}'),
              ],
            ],
          )
        : Text(widget.controller.statusMessage),
    actions: [
      TextButton(
        onPressed: () {
          _clearSensitive();
          Navigator.of(context).pop();
        },
        child: const Text('Tutup'),
      ),
      if (widget.controller.canReveal && _credential == null)
        FilledButton(
          onPressed: _busy ? null : _reveal,
          child: Text(_busy ? 'Memeriksa…' : 'Tampilkan password sementara'),
        ),
    ],
  );
}
