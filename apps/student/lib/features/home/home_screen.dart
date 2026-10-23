import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';

class StudentHomeScreen extends StatefulWidget {
  const StudentHomeScreen({
    super.key,
    required this.api,
    required this.profile,
    required this.onOpenQr,
    required this.onOpenRecord,
    required this.onOpenHistory,
  });

  final ApiClient api;
  final MeDto profile;
  final VoidCallback onOpenQr;
  final ValueChanged<String> onOpenRecord;
  final VoidCallback onOpenHistory;

  @override
  State<StudentHomeScreen> createState() => _StudentHomeScreenState();
}

class _StudentHomeScreenState extends State<StudentHomeScreen> {
  late Future<_HomeData> _data;

  @override
  void initState() {
    super.initState();
    _data = _load();
  }

  Future<_HomeData> _load() async {
    final responses = await Future.wait([
      widget.api.get('/student/summary'),
      widget.api.get('/student/records', query: const {'per_page': 3}),
    ]);
    final recordResponse = responses[1];
    final records = (recordResponse['data'] as List<dynamic>? ?? const [])
        .map((entry) => PointRecordDto.fromJson(entry as Map<String, dynamic>))
        .toList();
    return _HomeData(SummaryDto.fromJson(responses[0]), records);
  }

  Future<void> _reload() async {
    final next = _load();
    setState(() => _data = next);
    await next;
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<_HomeData>(
    future: _data,
    builder: (context, snapshot) {
      if (snapshot.hasError) {
        return _LoadFailure(onRetry: _reload);
      }
      if (!snapshot.hasData) {
        return const Center(
          child: CircularProgressIndicator(semanticsLabel: 'Memuat beranda'),
        );
      }
      final data = snapshot.data!;
      return RefreshIndicator(
        onRefresh: _reload,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
          children: [
            Text(
              'Halo, ${widget.profile.account.name}',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 4),
            Wrap(
              spacing: 16,
              runSpacing: 4,
              children: [
                _PlacementLabel(profile: widget.profile),
                Text(
                  widget.profile.currentSemester?.name ??
                      'Semester aktif belum tersedia',
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              ],
            ),
            const SizedBox(height: 24),
            Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(24),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Poin semester ini',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 12),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: _TotalTile(
                          label: 'Prestasi',
                          value: data.summary.achievement,
                        ),
                      ),
                      Container(
                        width: 1,
                        height: 72,
                        margin: const EdgeInsets.symmetric(horizontal: 12),
                        color: Theme.of(context).colorScheme.outlineVariant,
                      ),
                      Expanded(
                        child: _TotalTile(
                          label: 'Pelanggaran',
                          value: data.summary.violation,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton(
                      onPressed: widget.onOpenQr,
                      child: Wrap(
                        alignment: WrapAlignment.center,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        spacing: 12,
                        runSpacing: 4,
                        children: [
                          const Text('Tampilkan QR'),
                          Container(
                            width: 36,
                            height: 36,
                            decoration: const BoxDecoration(
                              color: Color(0x332E3439),
                              shape: BoxShape.circle,
                            ),
                            child: const Icon(Icons.qr_code_2, size: 20),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
            if (data.summary.achievement == null ||
                data.summary.violation == null)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  'Total belum tersedia karena semester aktif belum ditetapkan.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            if (data.summary.latestThresholdStatus case final status?)
              Card(
                margin: const EdgeInsets.only(top: 16),
                child: ListTile(
                  leading: const Icon(Icons.info_outline),
                  title: Text('${status['label'] ?? 'Tindak lanjut'}'),
                  subtitle: Text('${status['recommended_action'] ?? ''}'),
                ),
              ),
            const SizedBox(height: 28),
            Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              spacing: 8,
              runSpacing: 4,
              children: [
                Text(
                  'Catatan terbaru',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                TextButton(
                  onPressed: widget.onOpenHistory,
                  child: const Text('Lihat semua'),
                ),
              ],
            ),
            if (data.records.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 20),
                child: Text('Belum ada catatan.'),
              )
            else
              ...data.records.map(
                (record) => _RecentRecord(
                  record: record,
                  schoolTimezone: widget.profile.schoolTimezone,
                  onTap: () => widget.onOpenRecord(record.id),
                ),
              ),
          ],
        ),
      );
    },
  );
}

class _PlacementLabel extends StatelessWidget {
  const _PlacementLabel({required this.profile});
  final MeDto profile;

  @override
  Widget build(BuildContext context) {
    final enrollment = profile.ownEnrollment;
    if (enrollment == null) {
      return Text(
        'Belum ada penempatan aktif',
        style: Theme.of(context).textTheme.bodyMedium,
      );
    }
    return Text.rich(
      TextSpan(
        children: [
          TextSpan(
            text: enrollment.classroom.name,
            style: const TextStyle(decoration: TextDecoration.underline),
          ),
          TextSpan(text: ' · ${enrollment.major.name}'),
        ],
      ),
      style: Theme.of(context).textTheme.bodyMedium,
    );
  }
}

class _HomeData {
  const _HomeData(this.summary, this.records);
  final SummaryDto summary;
  final List<PointRecordDto> records;
}

class _TotalTile extends StatelessWidget {
  const _TotalTile({required this.label, required this.value});
  final String label;
  final int? value;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 8),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          value?.toString() ?? '—',
          style: Theme.of(context).textTheme.headlineLarge?.copyWith(
            fontWeight: FontWeight.w700,
            color: Theme.of(context).colorScheme.onSurface,
          ),
        ),
        Text(label, style: Theme.of(context).textTheme.bodyMedium),
      ],
    ),
  );
}

class _RecentRecord extends StatelessWidget {
  const _RecentRecord({
    required this.record,
    required this.schoolTimezone,
    required this.onTap,
  });
  final PointRecordDto record;
  final String? schoolTimezone;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final revision = record.latestRevision ?? const <String, dynamic>{};
    final date = formatSchoolDate(record.occurredAt, schoolTimezone);
    return ListTile(
      contentPadding: EdgeInsets.zero,
      title: Text(revision['name'] as String? ?? 'Catatan poin'),
      subtitle: Text(date ?? 'Waktu sekolah belum tersedia'),
      trailing: const Icon(Icons.chevron_right),
      onTap: onTap,
    );
  }
}

class _LoadFailure extends StatelessWidget {
  const _LoadFailure({required this.onRetry});
  final Future<void> Function() onRetry;
  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Text('Beranda tidak dapat dimuat. Periksa koneksi internet.'),
          const SizedBox(height: 12),
          FilledButton(onPressed: onRetry, child: const Text('Coba lagi')),
        ],
      ),
    ),
  );
}
