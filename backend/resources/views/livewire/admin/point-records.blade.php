<div>
    <div class="mb-6">
        <p class="admin-muted text-sm">Poin · Riwayat sekolah</p>
        <h1 class="mt-1 text-2xl font-semibold md:text-[28px]">Catatan poin</h1>
        <p class="admin-muted mt-2">Catatan menunggu pemeriksaan masih berlaku sampai diputuskan. Perubahan administratif tercatat dalam audit.</p>
    </div>
    @if ($message)<p role="status" class="admin-card mb-4 border-l-4 border-teal-700 p-4">{{ $message }}</p>@endif
    @if ($errors->has('reauthentication'))<p role="alert" class="admin-card mb-4 p-4">{{ $errors->first('reauthentication') }} <a class="admin-link-inline" href="{{ route('admin.confirm-password') }}">Konfirmasi password admin</a></p>@endif
    @if ($errors->has('revision'))<p role="alert" class="admin-card mb-4 p-4">{{ $errors->first('revision') }} <a class="admin-link-inline" href="{{ route('admin.point-records') }}">Muat ulang daftar</a></p>@endif
    @if ($target)
        <section class="admin-card mb-6 p-5" aria-labelledby="record-edit-title">
            <h2 id="record-edit-title" class="text-lg font-semibold">Koreksi catatan</h2>
            <p class="admin-muted mt-1">{{ $target->student->user->name }} · {{ $target->latestRevision->name_snapshot }} · versi {{ $expectedRevision }}. Identitas siswa dan semester berasal dari catatan asli dan tidak dapat dipindahkan.</p>
            <form wire:submit="revise" class="mt-4 grid grid-cols-1 items-end gap-4 sm:grid-cols-2">
                <div><label for="record-operation" class="admin-label">Perubahan</label><select id="record-operation" wire:model.live="operation" class="admin-control w-full"><option value="revise">Koreksi catatan</option><option value="cancel">Batalkan catatan</option></select>@error('operation')<p class="admin-error">{{ $message }}</p>@enderror</div>
                @if ($operation === 'revise')
                    <div><label for="record-point-type" class="admin-label">Jenis poin terbaru</label><select id="record-point-type" wire:model="pointTypeVersionId" class="admin-control w-full"><option value="">Pertahankan jenis saat ini</option>@foreach ($versions as $version)<option value="{{ $version->id }}">{{ $version->name }} · {{ $version->points }} poin · versi {{ $version->version }}</option>@endforeach</select>@error('pointTypeVersionId')<p class="admin-error">{{ $message }}</p>@enderror</div>
                    <div class="sm:col-span-2"><label for="record-description" class="admin-label">Keterangan</label><textarea id="record-description" wire:model="description" rows="3" class="admin-control w-full"></textarea>@error('description')<p class="admin-error">{{ $message }}</p>@enderror</div>
                    <div class="sm:col-span-2"><label for="record-attachment" class="admin-label">Bukti privat</label><select id="record-attachment" wire:model="attachmentId" class="admin-control w-full"><option value="">Pertahankan bukti saat ini</option>@foreach ($attachments as $attachment)<option value="{{ $attachment->id }}">{{ $attachment->mime_type }} · {{ number_format($attachment->size / 1024, 0) }} KB</option>@endforeach</select>@error('attachmentId')<p class="admin-error">{{ $message }}</p>@enderror</div>
                @else
                    <p class="rounded-md border border-[var(--border)] p-3 text-sm sm:col-span-2">Pembatalan mengubah status catatan. Siswa, guru, penempatan, dan semester tetap sama.</p>
                @endif
                <div class="sm:col-span-2"><label for="record-reason" class="admin-label">Alasan koreksi admin</label><textarea id="record-reason" wire:model="reason" rows="3" required minlength="5" maxlength="1000" class="admin-control w-full"></textarea>@error('reason')<p class="admin-error">{{ $message }}</p>@enderror</div>
                <div class="flex flex-wrap gap-3 sm:col-span-2"><button type="submit" class="admin-button-primary" wire:confirm="Simpan revisi dan selesaikan pemeriksaan pending jika ada?">Simpan koreksi</button><button type="button" class="admin-button-secondary" wire:click="cancelRevision">Tutup</button></div>
            </form>
        </section>
    @endif
    <section class="admin-card p-5" aria-labelledby="records-title">
        <div class="flex flex-wrap items-end justify-between gap-4"><div><h2 id="records-title" class="text-lg font-semibold">Riwayat catatan</h2><p class="admin-muted mt-1 text-sm">{{ $records->total() }} catatan tersedia.</p></div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div><label for="records-search" class="admin-label">Cari siswa, guru, keterangan</label><input id="records-search" wire:model.live.debounce.250ms="search" type="search" class="admin-control w-full"></div>
                <div><label for="records-kind" class="admin-label">Kategori</label><select id="records-kind" wire:model.live="kindFilter" class="admin-control w-full"><option value="">Semua kategori</option><option value="achievement">Prestasi</option><option value="violation">Pelanggaran</option></select></div>
                <div><label for="records-status" class="admin-label">Status catatan</label><select id="records-status" wire:model.live="statusFilter" class="admin-control w-full"><option value="">Semua status</option><option value="active">Berlaku</option><option value="cancelled">Dibatalkan</option></select></div>
            </div>
        </div>
        <div class="mt-4 overflow-x-auto"><table class="admin-table min-w-full"><caption class="sr-only">Catatan poin sekolah beserta status pemeriksaan dan perubahan</caption><thead><tr><th scope="col">Siswa dan kelas saat dicatat</th><th scope="col">Poin dan guru</th><th scope="col">Semester</th><th scope="col">Status</th><th scope="col">Pemeriksaan</th><th scope="col">Bukti</th><th scope="col">Aksi</th></tr></thead><tbody>
            @forelse ($records as $record)
                @php($revision = $record->latestRevision)
                @php($pending = $record->reviewRequests->firstWhere('status', 'pending'))
                <tr><td><strong>{{ $record->student->user->name }}</strong><br><span class="admin-muted">{{ $record->class_snapshot }} · {{ $record->student->nisn }}</span></td><td>{{ $revision->name_snapshot }} · {{ $revision->points_snapshot }} poin<br><span class="admin-muted">{{ $record->teacher->user->name }}</span></td><td>{{ $record->semester->name }}</td><td><span class="admin-status">{{ $revision->status === 'active' ? 'Berlaku' : 'Dibatalkan' }}</span></td><td>@if ($pending)<a class="admin-link-inline" href="{{ route('admin.reviews.show', $pending->id) }}">Menunggu pemeriksaan</a>@else<span class="admin-muted">Tidak ada pemeriksaan pending</span>@endif</td><td>@if ($revision->attachment_id)<a class="admin-link-inline" href="{{ route('admin.attachments.show', $revision->attachment_id) }}" target="_blank" rel="noopener">Lihat bukti privat</a>@else<span class="admin-muted">Tidak ada bukti</span>@endif</td><td><button type="button" class="admin-button-secondary" wire:click="beginRevision('{{ $record->id }}')">Koreksi</button></td></tr>
            @empty
                <tr><td colspan="7" class="py-8 text-center">Belum ada catatan yang cocok dengan filter ini.</td></tr>
            @endforelse
        </tbody></table></div>
        <div class="mt-4">{{ $records->links() }}</div>
    </section>
</div>
