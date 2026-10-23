<div>
    <div class="mb-6"><p class="admin-muted text-sm">Administrasi</p><h1 class="mt-1 text-2xl font-semibold md:text-[28px]">Laporan</h1><p class="admin-muted mt-2">Rekap memakai kelas pada semester saat catatan dibuat. Ekspor diproses privat dan hanya dapat diunduh oleh pemiliknya.</p></div>
    @if ($message)<p role="status" class="admin-card mb-4 border-l-4 border-teal-700 p-4">{{ $message }}</p>@endif
    @if ($errors->has('reauthentication'))<p role="alert" class="admin-card mb-4 p-4">{{ $errors->first('reauthentication') }} <a class="admin-link-inline" href="{{ route('admin.confirm-password') }}">Konfirmasi password admin</a></p>@endif
    <section class="admin-card p-5" aria-labelledby="report-filters-title">
        <h2 id="report-filters-title" class="text-lg font-semibold">Filter laporan poin</h2>
        <form wire:submit="applyFilters" class="mt-4 grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div><label for="report-semester" class="admin-label">Semester</label><select id="report-semester" wire:model.live="semesterId" class="admin-control"><option value="">Pilih semester</option>@foreach ($semesters as $semester)<option value="{{ $semester->id }}">{{ $semester->name }} · {{ $semester->academicYear->name }}</option>@endforeach</select>@error('semesterId')<p class="admin-error">{{ $message }}</p>@enderror</div>
            <div><label for="report-class" class="admin-label">Kelas historis</label><select id="report-class" wire:model="classroomId" class="admin-control"><option value="">Semua kelas</option>@foreach ($classrooms as $classroom)<option value="{{ $classroom->id }}">{{ $classroom->name }} · {{ $classroom->major?->name }}</option>@endforeach</select>@error('classroomId')<p class="admin-error">{{ $message }}</p>@enderror</div>
            <div><label for="report-category" class="admin-label">Kategori</label><select id="report-category" wire:model="category" class="admin-control"><option value="">Semua</option><option value="achievement">Prestasi</option><option value="violation">Pelanggaran</option></select></div>
            <div><label for="report-status" class="admin-label">Status catatan</label><select id="report-status" wire:model="status" class="admin-control"><option value="">Semua</option><option value="active">Berlaku</option><option value="cancelled">Dibatalkan</option></select></div>
            <button class="admin-button-primary" type="submit">Tampilkan laporan</button>
        </form>
        @if ($hasReport)
            <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2"><div class="admin-card p-4"><p class="admin-muted text-sm">Total prestasi berlaku</p><p class="mt-1 text-2xl font-semibold">{{ number_format($summary['achievement'], 0, ',', '.') }} poin</p></div><div class="admin-card p-4"><p class="admin-muted text-sm">Total pelanggaran berlaku</p><p class="mt-1 text-2xl font-semibold">{{ number_format($summary['violation'], 0, ',', '.') }} poin</p></div></div>
            <div class="mt-4 overflow-x-auto"><table class="admin-table min-w-full"><caption class="sr-only">Detail laporan poin sesuai filter</caption><thead><tr><th scope="col">Waktu sekolah</th><th scope="col">Siswa</th><th scope="col">NISN</th><th scope="col">Kelas historis</th><th scope="col">Kategori</th><th scope="col">Jenis poin</th><th scope="col">Nilai</th><th scope="col">Status</th></tr></thead><tbody>
                @forelse ($rows as $row)<tr><td>{{ $row['occurred_at'] }}</td><td>{{ $row['student'] }}</td><td>{{ $row['nisn'] }}</td><td>{{ $row['class'] }}</td><td>{{ $row['category'] === 'achievement' ? 'Prestasi' : 'Pelanggaran' }}</td><td>{{ $row['point_name'] }}</td><td>{{ $row['points'] }}</td><td>{{ $row['status'] === 'active' ? 'Berlaku' : 'Dibatalkan' }}</td></tr>
                @empty<tr><td colspan="8" class="py-8 text-center">Belum ada catatan pada filter ini.</td></tr>@endforelse
            </tbody></table></div>
        @else
            <p role="status" class="admin-muted mt-5">Pilih semester dan tampilkan laporan untuk melihat hasil.</p>
        @endif
    </section>
    <section class="admin-card mt-6 p-5" aria-labelledby="report-export-title">
        <h2 id="report-export-title" class="text-lg font-semibold">Buat ekspor</h2><p class="admin-muted mt-1 text-sm">Ekspor memakai filter yang sama. Unduhan tersedia tujuh hari setelah selesai.</p>
        <form wire:submit="requestExport" class="mt-4 flex flex-wrap items-end gap-4"><div><label for="report-format" class="admin-label">Format</label><select id="report-format" wire:model="exportFormat" class="admin-control"><option value="xlsx">Excel (.xlsx)</option><option value="pdf">PDF</option></select>@error('exportFormat')<p class="admin-error">{{ $message }}</p>@enderror</div><button type="submit" class="admin-button-primary">Minta ekspor</button></form>
    </section>
    <section class="admin-card mt-6 p-5" aria-labelledby="report-jobs-title">
        <div class="flex flex-wrap items-end justify-between gap-3"><div><h2 id="report-jobs-title" class="text-lg font-semibold">Ekspor saya</h2><p class="admin-muted mt-1 text-sm">Status pemrosesan laporan</p></div><button type="button" class="admin-button-secondary" onclick="window.location.reload()">Muat ulang status</button></div>
        <div class="mt-4 overflow-x-auto"><table class="admin-table min-w-full"><caption class="sr-only">Status ekspor laporan milik admin yang sedang masuk</caption><thead><tr><th scope="col">Diminta</th><th scope="col">Semester</th><th scope="col">Format</th><th scope="col">Status</th><th scope="col">Kedaluwarsa</th><th scope="col">Aksi</th></tr></thead><tbody>
            @forelse ($exports as $export)<tr><td>{{ $export->created_at?->timezone($schoolTimezone)->format('d M Y H:i') }}</td><td>{{ $semesters->firstWhere('id', $export->filters['semester_id'] ?? null)?->name ?? 'Semester historis' }}</td><td>{{ strtoupper($export->filters['format'] ?? 'xlsx') }}</td><td><span class="admin-status">{{ ['queued' => 'Menunggu antrean', 'processing' => 'Sedang diproses', 'completed' => 'Selesai', 'failed' => 'Gagal'][$export->status] ?? 'Status tidak dikenal' }}</span></td><td>{{ $export->expires_at?->timezone($schoolTimezone)->format('d M Y H:i') ?? '—' }}</td><td>@if ($export->status === 'completed' && $export->expires_at?->isFuture())<a class="admin-button-secondary inline-flex items-center" href="{{ route('admin.reports.download', $export) }}">Unduh</a>@elseif ($export->status === 'completed')<span class="admin-muted">Ekspor kedaluwarsa</span>@else<span class="admin-muted">Belum tersedia</span>@endif</td></tr>
            @empty<tr><td colspan="6" class="py-8 text-center">Belum ada permintaan ekspor.</td></tr>@endforelse
        </tbody></table></div><div class="mt-4">{{ $exports->links() }}</div>
    </section>
</div>
