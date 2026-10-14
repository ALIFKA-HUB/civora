import 'package:flutter/material.dart';

class PasswordForm extends StatefulWidget {
  const PasswordForm({
    super.key,
    required this.onSubmit,
    required this.isBusy,
    this.activation = false,
    this.errorMessage,
  });

  final Future<void> Function(String currentPassword, String newPassword)
  onSubmit;
  final bool isBusy;
  final bool activation;
  final String? errorMessage;

  @override
  State<PasswordForm> createState() => _PasswordFormState();
}

class _PasswordFormState extends State<PasswordForm> {
  final _formKey = GlobalKey<FormState>();
  final _current = TextEditingController();
  final _newPassword = TextEditingController();
  final _confirmation = TextEditingController();
  bool _show = false;

  @override
  void dispose() {
    _current.dispose();
    _newPassword.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Form(
    key: _formKey,
    child: AutofillGroup(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (widget.activation) ...[
            Text(
              'Akun ini memakai password sementara. Buat password baru sebelum melanjutkan.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 20),
          ],
          _passwordField(
            _current,
            widget.activation ? 'Password sementara' : 'Password saat ini',
            validator: (value) => value == null || value.isEmpty
                ? 'Password saat ini wajib diisi.'
                : null,
          ),
          const SizedBox(height: 16),
          _passwordField(
            _newPassword,
            'Password baru',
            validator: _validateNewPassword,
            autofillHints: const [AutofillHints.newPassword],
          ),
          const SizedBox(height: 16),
          _passwordField(
            _confirmation,
            'Konfirmasi password baru',
            validator: (value) => value != _newPassword.text
                ? 'Konfirmasi password belum sama.'
                : null,
            autofillHints: const [AutofillHints.newPassword],
            onSubmitted: (_) => _submit(),
          ),
          if (widget.errorMessage != null) ...[
            const SizedBox(height: 16),
            Semantics(
              liveRegion: true,
              child: Text(
                widget.errorMessage!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
          ],
          const SizedBox(height: 24),
          FilledButton(
            onPressed: widget.isBusy ? null : _submit,
            child: widget.isBusy
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : Text(widget.activation ? 'Aktifkan akun' : 'Ganti password'),
          ),
        ],
      ),
    ),
  );

  Widget _passwordField(
    TextEditingController controller,
    String label, {
    required String? Function(String?) validator,
    Iterable<String>? autofillHints,
    ValueChanged<String>? onSubmitted,
  }) => TextFormField(
    controller: controller,
    enabled: !widget.isBusy,
    obscureText: !_show,
    decoration: InputDecoration(
      labelText: label,
      suffixIcon: IconButton(
        tooltip: _show ? 'Sembunyikan password' : 'Tampilkan password',
        onPressed: widget.isBusy ? null : () => setState(() => _show = !_show),
        icon: Icon(
          _show ? Icons.visibility_off_outlined : Icons.visibility_outlined,
        ),
      ),
    ),
    textInputAction: TextInputAction.next,
    autofillHints: autofillHints,
    validator: validator,
    onFieldSubmitted: onSubmitted,
  );

  String? _validateNewPassword(String? value) {
    final length = value?.runes.length ?? 0;
    if (length < 12) return 'Password baru minimal 12 karakter.';
    if (length > 1024) return 'Password baru maksimal 1.024 karakter.';
    if (value == _current.text)
      return 'Password baru harus berbeda dari password saat ini.';
    return null;
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    await widget.onSubmit(_current.text, _newPassword.text);
  }
}
