import 'package:flutter/material.dart';

class LoginForm extends StatefulWidget {
  const LoginForm({
    super.key,
    required this.identifierLabel,
    required this.onSubmit,
    required this.isBusy,
    this.errorMessage,
    this.restoreMessage,
    this.infoMessage,
  });

  final String identifierLabel;
  final Future<void> Function(String identifier, String password) onSubmit;
  final bool isBusy;
  final String? errorMessage;
  final String? restoreMessage;
  final String? infoMessage;

  @override
  State<LoginForm> createState() => _LoginFormState();
}

class _LoginFormState extends State<LoginForm> {
  final _formKey = GlobalKey<FormState>();
  final _identifier = TextEditingController();
  final _password = TextEditingController();
  bool _obscurePassword = true;

  @override
  void dispose() {
    _identifier.dispose();
    _password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Form(
    key: _formKey,
    child: AutofillGroup(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextFormField(
            controller: _identifier,
            enabled: !widget.isBusy,
            decoration: InputDecoration(labelText: widget.identifierLabel),
            keyboardType: TextInputType.text,
            textInputAction: TextInputAction.next,
            autofillHints: const [AutofillHints.username],
            validator: (value) => value == null || value.trim().isEmpty
                ? 'Identitas wajib diisi.'
                : null,
          ),
          const SizedBox(height: 16),
          TextFormField(
            controller: _password,
            enabled: !widget.isBusy,
            obscureText: _obscurePassword,
            decoration: InputDecoration(
              labelText: 'Password',
              suffixIcon: IconButton(
                tooltip: _obscurePassword
                    ? 'Tampilkan password'
                    : 'Sembunyikan password',
                onPressed: widget.isBusy
                    ? null
                    : () =>
                          setState(() => _obscurePassword = !_obscurePassword),
                icon: Icon(
                  _obscurePassword
                      ? Icons.visibility_outlined
                      : Icons.visibility_off_outlined,
                ),
              ),
            ),
            textInputAction: TextInputAction.done,
            autofillHints: const [AutofillHints.password],
            validator: (value) =>
                value == null || value.isEmpty ? 'Password wajib diisi.' : null,
            onFieldSubmitted: (_) => _submit(),
          ),
          if (widget.restoreMessage != null) ...[
            const SizedBox(height: 16),
            _Message(
              text: widget.restoreMessage!,
              icon: Icons.wifi_off_outlined,
            ),
          ],
          if (widget.infoMessage != null) ...[
            const SizedBox(height: 16),
            _Message(text: widget.infoMessage!, icon: Icons.info_outline),
          ],
          if (widget.errorMessage != null) ...[
            const SizedBox(height: 16),
            _Message(
              text: widget.errorMessage!,
              icon: Icons.error_outline,
              isError: true,
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
                : const Text('Masuk'),
          ),
          const SizedBox(height: 16),
          Text(
            'Masalah masuk? Hubungi guru atau admin sekolah untuk bantuan akun.',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    ),
  );

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    await widget.onSubmit(_identifier.text.trim(), _password.text);
  }
}

class _Message extends StatelessWidget {
  const _Message({
    required this.text,
    required this.icon,
    this.isError = false,
  });
  final String text;
  final IconData icon;
  final bool isError;

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    final color = isError ? colors.error : colors.primary;
    return Semantics(
      liveRegion: true,
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.08),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: color),
            const SizedBox(width: 8),
            Expanded(
              child: Text(text, style: TextStyle(color: color)),
            ),
          ],
        ),
      ),
    );
  }
}
