import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';

class TeacherCorrectionController {
  TeacherCorrectionController(this.api, {required this.recordId});
  final ApiClient api;
  final String recordId;
  bool recordTotalChanged = false;

  Future<ReviewDto> request({
    required String description,
    required String proposedDescription,
    String operation = 'revise',
    String? pointTypeVersionId,
  }) async {
    final reason = description.trim();
    if (reason.runes.length < 5 || reason.runes.length > 1000) {
      throw ArgumentError('Alasan harus berisi 5–1.000 karakter.');
    }
    final response = await api.post(
      '/teacher/records/$recordId/correction-requests',
      {
        'reason': reason,
        'proposed_change': operation == 'cancel'
            ? {'operation': 'cancel'}
            : {
                'operation': 'revise',
                if (proposedDescription.trim().isNotEmpty)
                  'description': proposedDescription.trim(),
                'point_type_version_id': ?pointTypeVersionId,
              },
      },
    );
    return ReviewDto.fromJson(response);
  }
}

class TeacherCorrectionScreen extends StatefulWidget {
  const TeacherCorrectionScreen({
    super.key,
    required this.api,
    required this.record,
    this.onSubmitted,
  });
  final ApiClient api;
  final Map<String, dynamic> record;
  final ValueChanged<ReviewDto>? onSubmitted;
  @override
  State<TeacherCorrectionScreen> createState() =>
      _TeacherCorrectionScreenState();
}

class _TeacherCorrectionScreenState extends State<TeacherCorrectionScreen> {
  late final TeacherCorrectionController _controller;
  final _reason = TextEditingController();
  final _description = TextEditingController();
  String _operation = 'revise';
  String? _error;
  bool _busy = false;
  ReviewDto? _review;
  List<PointTypeDto> _pointTypes = const [];
  String? _pointTypeVersionId;

  @override
  void initState() {
    super.initState();
    _controller = TeacherCorrectionController(
      widget.api,
      recordId: widget.record['id'] as String,
    );
    final latest = widget.record['latest_revision'] as Map<String, dynamic>?;
    _description.text = latest?['description'] as String? ?? '';
    _loadPointTypes();
  }

  @override
  void dispose() {
    _reason.dispose();
    _description.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final latest =
        widget.record['latest_revision'] as Map<String, dynamic>? ?? const {};
    if (_review != null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Permintaan koreksi')),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.hourglass_top, size: 48),
                const SizedBox(height: 16),
                Text(
                  'Permintaan koreksi menunggu pemeriksaan.',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const SizedBox(height: 8),
                const Text(
                  'Poin pada catatan tetap berlaku sampai keputusan admin.',
                ),
                const SizedBox(height: 20),
                FilledButton(
                  onPressed: () => Navigator.pop(context),
                  child: const Text('Selesai'),
                ),
              ],
            ),
          ),
        ),
      );
    }
    return Scaffold(
      appBar: AppBar(title: const Text('Minta koreksi')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            '${latest['name'] ?? 'Catatan'} · ${latest['points'] ?? '—'} poin',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 8),
          Text(latest['description'] as String? ?? 'Tanpa keterangan'),
          const SizedBox(height: 20),
          SegmentedButton<String>(
            segments: const [
              ButtonSegment(value: 'revise', label: Text('Koreksi')),
              ButtonSegment(value: 'cancel', label: Text('Pembatalan')),
            ],
            selected: {_operation},
            onSelectionChanged: (value) =>
                setState(() => _operation = value.first),
          ),
          const SizedBox(height: 16),
          if (_operation == 'revise')
            DropdownButtonFormField<String?>(
              initialValue: _pointTypeVersionId,
              decoration: const InputDecoration(
                labelText: 'Jenis poin yang diusulkan (opsional)',
              ),
              items: [
                const DropdownMenuItem(
                  value: null,
                  child: Text('Pertahankan jenis saat ini'),
                ),
                ..._pointTypes.map(
                  (type) => DropdownMenuItem(
                    value: type.versionId,
                    child: Text('${type.name} · ${type.points} poin'),
                  ),
                ),
              ],
              onChanged: (value) => setState(() => _pointTypeVersionId = value),
            ),
          if (_operation == 'revise')
            TextField(
              controller: _description,
              minLines: 2,
              maxLines: 4,
              decoration: const InputDecoration(labelText: 'Usulan keterangan'),
            ),
          const SizedBox(height: 16),
          TextField(
            controller: _reason,
            minLines: 3,
            maxLines: 6,
            decoration: const InputDecoration(
              labelText: 'Alasan (5–1.000 karakter)',
            ),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ),
          const SizedBox(height: 20),
          FilledButton(
            onPressed: _busy ? null : _submit,
            child: Text(_busy ? 'Mengirim…' : 'Kirim permintaan'),
          ),
        ],
      ),
    );
  }

  Future<void> _submit() async {
    final originalDescription =
        (widget.record['latest_revision']
                as Map<String, dynamic>?)?['description']
            as String? ??
        '';
    if (_reason.text.trim().runes.length < 5 ||
        _reason.text.trim().runes.length > 1000 ||
        (_operation == 'revise' &&
            ((_description.text.trim() != originalDescription &&
                    _description.text.trim().runes.length < 5) ||
                (_description.text.trim() == originalDescription &&
                    _pointTypeVersionId == null)))) {
      setState(
        () => _error =
            'Alasan dan usulan keterangan harus berisi minimal 5 karakter.',
      );
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final review = await _controller.request(
        description: _reason.text,
        proposedDescription: _description.text,
        operation: _operation,
        pointTypeVersionId: _pointTypeVersionId,
      );
      if (mounted) {
        setState(() => _review = review);
        widget.onSubmitted?.call(review);
      }
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } catch (_) {
      if (mounted) {
        setState(
          () => _error =
              'Tidak dapat mengirim permintaan. Periksa koneksi lalu coba lagi.',
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _loadPointTypes() async {
    try {
      final response = await widget.api.get('/teacher/point-types');
      if (!mounted) return;
      setState(
        () => _pointTypes = (response['data'] as List<dynamic>? ?? const [])
            .map((item) => PointTypeDto.fromJson(item as Map<String, dynamic>))
            .toList(),
      );
    } on ApiException {
      // Description-only corrections remain available without a current catalogue.
    }
  }
}
