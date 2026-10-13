import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';

class StudentRecordsScreen extends StatefulWidget {
  const StudentRecordsScreen({
    super.key,
    required this.api,
    required this.schoolTimezone,
    required this.onOpenRecord,
  });

  final ApiClient api;
  final String? schoolTimezone;
  final ValueChanged<String> onOpenRecord;

  @override
  State<StudentRecordsScreen> createState() => _StudentRecordsScreenState();
}

class _StudentRecordsScreenState extends State<StudentRecordsScreen> {
  final _scroll = ScrollController();
  List<PointRecordDto> _records = [];
  List<Map<String, dynamic>> _semesters = [];
  String? _semesterId;
  String? _category;
  String? _status;
  String? _error;
  int _page = 1;
  int _lastPage = 1;
  int _request = 0;
  bool _loading = true;
  bool _loadingMore = false;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
    _load(reset: true);
  }

  @override
  void dispose() {
    _request++;
    _scroll.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scroll.position.extentAfter < 360 &&
        !_loading &&
        !_loadingMore &&
        _page < _lastPage) {
      _loadMore();
    }
  }

  Future<void> _load({required bool reset}) async {
    final request = ++_request;
    if (mounted) {
      setState(() {
        _error = null;
        _loading = reset;
        _loadingMore = !reset;
        if (reset) {
          _records = [];
          _page = 1;
        }
      });
    }
    final query = <String, dynamic>{
      'page': reset ? 1 : _page + 1,
      'per_page': 20,
    };
    if (_semesterId != null) query['semester_id'] = _semesterId;
    if (_category != null) query['category'] = _category;
    if (_status != null) query['status'] = _status;
    try {
      final response = await widget.api.get('/student/records', query: query);
      final records = (response['data'] as List<dynamic>? ?? const [])
          .map((item) => PointRecordDto.fromJson(item as Map<String, dynamic>))
          .toList();
      final meta = response['meta'] as Map<String, dynamic>? ?? const {};
      if (!mounted || request != _request) return;
      setState(() {
        _records = reset ? records : [..._records, ...records];
        _semesters = (meta['available_semesters'] as List<dynamic>? ?? const [])
            .map((item) => item as Map<String, dynamic>)
            .toList();
        _page = meta['current_page'] as int? ?? 1;
        _lastPage = meta['last_page'] as int? ?? 1;
        _loading = false;
        _loadingMore = false;
      });
    } catch (_) {
      if (!mounted || request != _request) return;
      setState(() {
        _error = 'Riwayat tidak dapat dimuat. Periksa koneksi internet.';
        _loading = false;
        _loadingMore = false;
      });
    }
  }

  Future<void> _loadMore() => _load(reset: false);

  Future<void> _setFilter({
    String? semesterId,
    String? category,
    String? status,
  }) async {
    setState(() {
      _semesterId = semesterId;
      _category = category;
      _status = status;
    });
    await _load(reset: true);
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _records.isEmpty) {
      return const Center(
        child: CircularProgressIndicator(semanticsLabel: 'Memuat riwayat'),
      );
    }
    if (_error != null && _records.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(_error!),
              const SizedBox(height: 12),
              FilledButton(
                onPressed: () => _load(reset: true),
                child: const Text('Coba lagi'),
              ),
            ],
          ),
        ),
      );
    }
    return RefreshIndicator(
      onRefresh: () => _load(reset: true),
      child: CustomScrollView(
        controller: _scroll,
        slivers: [
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
            sliver: SliverToBoxAdapter(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Riwayat catatan',
                    style: Theme.of(context).textTheme.headlineSmall,
                  ),
                  const SizedBox(height: 16),
                  DropdownButtonFormField<String?>(
                    initialValue: _semesterId,
                    decoration: const InputDecoration(labelText: 'Semester'),
                    items: [
                      const DropdownMenuItem<String?>(
                        value: null,
                        child: Text('Semua semester'),
                      ),
                      ..._semesters.map(
                        (semester) => DropdownMenuItem<String?>(
                          value: semester['id'] as String,
                          child: Text(
                            semester['name'] as String? ?? 'Semester',
                          ),
                        ),
                      ),
                    ],
                    onChanged: (value) => _setFilter(
                      semesterId: value,
                      category: _category,
                      status: _status,
                    ),
                  ),
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 8,
                    runSpacing: 4,
                    children: [
                      _FilterChip(
                        label: 'Semua jenis',
                        selected: _category == null,
                        onTap: () => _setFilter(
                          semesterId: _semesterId,
                          category: null,
                          status: _status,
                        ),
                      ),
                      _FilterChip(
                        label: 'Prestasi',
                        selected: _category == 'achievement',
                        onTap: () => _setFilter(
                          semesterId: _semesterId,
                          category: 'achievement',
                          status: _status,
                        ),
                      ),
                      _FilterChip(
                        label: 'Pelanggaran',
                        selected: _category == 'violation',
                        onTap: () => _setFilter(
                          semesterId: _semesterId,
                          category: 'violation',
                          status: _status,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 4,
                    children: [
                      _FilterChip(
                        label: 'Semua status',
                        selected: _status == null,
                        onTap: () => _setFilter(
                          semesterId: _semesterId,
                          category: _category,
                          status: null,
                        ),
                      ),
                      _FilterChip(
                        label: 'Aktif',
                        selected: _status == 'active',
                        onTap: () => _setFilter(
                          semesterId: _semesterId,
                          category: _category,
                          status: 'active',
                        ),
                      ),
                      _FilterChip(
                        label: 'Dibatalkan',
                        selected: _status == 'cancelled',
                        onTap: () => _setFilter(
                          semesterId: _semesterId,
                          category: _category,
                          status: 'cancelled',
                        ),
                      ),
                    ],
                  ),
                  if (_error != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: Text(
                        _error!,
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),
          if (_records.isEmpty)
            const SliverFillRemaining(
              hasScrollBody: false,
              child: Center(child: Text('Belum ada catatan untuk filter ini.')),
            )
          else
            SliverList.builder(
              itemCount: _records.length,
              itemBuilder: (context, index) {
                final record = _records[index];
                final revision =
                    record.latestRevision ?? const <String, dynamic>{};
                final date = formatSchoolDate(
                  record.occurredAt,
                  widget.schoolTimezone,
                );
                final points = revision['points'] as int?;
                final statusLabel = _statusLabel(record, revision);
                return ListTile(
                  minVerticalPadding: 10,
                  contentPadding: const EdgeInsets.symmetric(horizontal: 20),
                  title: Text(revision['name'] as String? ?? 'Catatan poin'),
                  subtitle: Text(
                    '${date ?? 'Waktu sekolah belum tersedia'} · ${record.semester.name}\n$statusLabel',
                  ),
                  isThreeLine: true,
                  trailing: points == null
                      ? const Icon(Icons.chevron_right)
                      : Text('$points poin'),
                  onTap: () => widget.onOpenRecord(record.id),
                );
              },
            ),
          if (_loadingMore)
            const SliverToBoxAdapter(
              child: Padding(
                padding: EdgeInsets.all(16),
                child: Center(
                  child: CircularProgressIndicator(
                    semanticsLabel: 'Memuat catatan berikutnya',
                  ),
                ),
              ),
            ),
          const SliverToBoxAdapter(child: SizedBox(height: 24)),
        ],
      ),
    );
  }
}

String _statusLabel(PointRecordDto record, Map<String, dynamic> revision) {
  if (revision['status'] == 'cancelled') return 'Dibatalkan';
  return switch (record.reviewStatus) {
    'pending' => 'Menunggu pemeriksaan',
    'approved' => 'Keberatan disetujui',
    'rejected' => 'Keberatan ditolak',
    _ => 'Aktif',
  };
}

class _FilterChip extends StatelessWidget {
  const _FilterChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });
  final String label;
  final bool selected;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) => FilterChip(
    label: Text(label),
    selected: selected,
    onSelected: (_) => onTap(),
  );
}
