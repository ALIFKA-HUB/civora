import 'dart:typed_data';

import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';

import '../reviews/objection_screen.dart';

class StudentRecordDetailScreen extends StatefulWidget {
  const StudentRecordDetailScreen({
    super.key,
    required this.api,
    required this.recordId,
    required this.schoolTimezone,
  });

  final ApiClient api;
  final String recordId;
  final String? schoolTimezone;

  @override
  State<StudentRecordDetailScreen> createState() =>
      _StudentRecordDetailScreenState();
}

class _StudentRecordDetailScreenState extends State<StudentRecordDetailScreen> {
  late Future<PointRecordDto> _record;

  @override
  void initState() {
    super.initState();
    _record = _load();
  }

  Future<PointRecordDto> _load() async => PointRecordDto.fromJson(
    await widget.api.get('/student/records/${widget.recordId}'),
  );

  Future<void> _reload() async {
    final next = _load();
    setState(() => _record = next);
    await next;
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Detail catatan')),
    body: FutureBuilder<PointRecordDto>(
      future: _record,
      builder: (context, snapshot) {
        if (snapshot.hasError) {
          return Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Text('Catatan tidak dapat dimuat.'),
                  const SizedBox(height: 12),
                  FilledButton(
                    onPressed: _reload,
                    child: const Text('Coba lagi'),
                  ),
                ],
              ),
            ),
          );
        }
        if (!snapshot.hasData) {
          return const Center(
            child: CircularProgressIndicator(
              semanticsLabel: 'Memuat detail catatan',
            ),
          );
        }
        final record = snapshot.data!;
        final revision = record.latestRevision ?? const <String, dynamic>{};
        final attachmentId = revision['attachment_id'] as String?;
        final revisionNumber = revision['revision_number'] as int?;
        final description = revision['description'] as String?;
        final status = revision['status'] as String?;
        return ListView(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 28),
          children: [
            Text(
              revision['name'] as String? ?? 'Catatan poin',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 8),
            Text(
              '${revision['points'] ?? '—'} poin · ${_category(revision['category'] as String?)}',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 20),
            _DetailRow(label: 'Semester', value: record.semester.name),
            _DetailRow(
              label: 'Waktu',
              value:
                  formatSchoolDate(record.occurredAt, widget.schoolTimezone) ??
                  'Waktu sekolah belum tersedia',
            ),
            _DetailRow(
              label: 'Guru pencatat',
              value: record.teacher['name'] as String? ?? '—',
            ),
            _DetailRow(label: 'Status catatan', value: _recordStatus(status)),
            if (revisionNumber != null)
              _DetailRow(
                label: 'Revisi terakhir',
                value: 'Revisi $revisionNumber',
              ),
            const SizedBox(height: 16),
            Text('Keterangan', style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 8),
            Text(
              description?.isNotEmpty == true
                  ? description!
                  : 'Tidak ada keterangan.',
            ),
            if (attachmentId != null) ...[
              const SizedBox(height: 20),
              Text(
                'Bukti foto',
                style: Theme.of(context).textTheme.titleMedium,
              ),
              const SizedBox(height: 8),
              _PrivateEvidence(api: widget.api, attachmentId: attachmentId),
            ],
            const SizedBox(height: 20),
            _ReviewStatus(status: record.reviewStatus),
            if (record.reviewStatus != 'pending') ...[
              const SizedBox(height: 16),
              OutlinedButton.icon(
                onPressed: () async {
                  final submitted = await Navigator.of(context).push<bool>(
                    MaterialPageRoute(
                      builder: (_) => StudentObjectionScreen(
                        api: widget.api,
                        record: record,
                      ),
                    ),
                  );
                  if (submitted == true && mounted) await _reload();
                },
                icon: const Icon(Icons.rate_review_outlined),
                label: const Text('Ajukan keberatan'),
              ),
            ],
          ],
        );
      },
    ),
  );
}

String _category(String? category) => switch (category) {
  'achievement' => 'Prestasi',
  'violation' => 'Pelanggaran',
  _ => 'Jenis tidak tersedia',
};

String _recordStatus(String? status) => switch (status) {
  'active' => 'Aktif',
  'cancelled' => 'Dibatalkan',
  _ => 'Status belum tersedia',
};

class _DetailRow extends StatelessWidget {
  const _DetailRow({required this.label, required this.value});
  final String label;
  final String value;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 14),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.bodySmall),
        const SizedBox(height: 3),
        Text(value, style: Theme.of(context).textTheme.bodyLarge),
      ],
    ),
  );
}

class _ReviewStatus extends StatelessWidget {
  const _ReviewStatus({required this.status});
  final String? status;
  @override
  Widget build(BuildContext context) {
    final text = switch (status) {
      'pending' =>
        'Keberatan menunggu pemeriksaan. Poin tetap berlaku sampai keputusan admin.',
      'approved' => 'Keberatan disetujui admin.',
      'rejected' => 'Keberatan ditolak admin. Poin tetap berlaku.',
      _ => 'Belum ada keberatan.',
    };
    return Semantics(
      container: true,
      label: 'Status pemeriksaan: $text',
      child: Card(
        child: Padding(padding: const EdgeInsets.all(16), child: Text(text)),
      ),
    );
  }
}

class _PrivateEvidence extends StatefulWidget {
  const _PrivateEvidence({required this.api, required this.attachmentId});
  final ApiClient api;
  final String attachmentId;
  @override
  State<_PrivateEvidence> createState() => _PrivateEvidenceState();
}

class _PrivateEvidenceState extends State<_PrivateEvidence> {
  late Future<Uint8List> _bytes;
  @override
  void initState() {
    super.initState();
    _bytes = widget.api.getBytes('/attachments/${widget.attachmentId}');
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Uint8List>(
    future: _bytes,
    builder: (context, snapshot) {
      if (snapshot.hasError) return const Text('Bukti tidak dapat dimuat.');
      if (!snapshot.hasData) {
        return const SizedBox(
          height: 180,
          child: Center(
            child: CircularProgressIndicator(semanticsLabel: 'Memuat bukti'),
          ),
        );
      }
      return ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: Image.memory(
          snapshot.data!,
          fit: BoxFit.contain,
          semanticLabel: 'Foto bukti privat',
        ),
      );
    },
  );
}
