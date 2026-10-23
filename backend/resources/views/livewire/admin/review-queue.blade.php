<div>
    <div class="mb-6">
        <p class="admin-muted text-sm">Poin · Pemeriksaan</p>
        <h1 class="mt-1 text-2xl font-semibold md:text-[28px]">Pemeriksaan</h1>
        <p class="admin-muted mt-2">Catatan berstatus menunggu tetap berlaku sampai keputusan disimpan.</p>
    </div>
    <section class="admin-card p-5" aria-labelledby="review-queue-title">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div><h2 id="review-queue-title" class="text-lg font-semibold">Antrean pemeriksaan</h2><p class="admin-muted mt-1 text-sm">{{ $reviews->total() }} permintaan menunggu pemeriksaan.</p></div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div><label for="review-search" class="admin-label">Cari pemohon atau catatan</label><input id="review-search" wire:model.live.debounce.250ms="search" type="search" class="admin-control w-full"></div>
                <div><label for="review-kind" class="admin-label">Jenis permintaan</label><select id="review-kind" wire:model.live="kindFilter" class="admin-control w-full"><option value="">Semua permintaan</option><option value="student_objection">Keberatan siswa</option><option value="teacher_correction">Koreksi guru</option></select></div>
            </div>
        </div>
        <div class="mt-4 overflow-x-auto"><table class="admin-table min-w-full">
            <caption class="sr-only">Permintaan pemeriksaan yang masih menunggu keputusan admin</caption>
            <thead><tr><th scope="col">Status</th><th scope="col">Pemohon</th><th scope="col">Siswa dan catatan</th><th scope="col">Diajukan</th><th scope="col">Aksi</th></tr></thead>
            <tbody>
                @forelse ($reviews as $review)
                    @php($record = $review->pointRecord)
                    <tr>
                        <td><span class="admin-status">Menunggu pemeriksaan</span></td>
                        <td><strong>{{ $review->kind === 'student_objection' ? 'Siswa' : 'Guru' }}</strong><br>{{ $review->requester->name }}</td>
                        <td>{{ $record->student->user->name }}<br><span class="admin-muted">{{ $record->latestRevision?->name_snapshot }} · {{ $record->latestRevision?->points_snapshot }} poin</span></td>
                        <td>{{ $review->created_at?->timezone(config('app.school_timezone', 'Asia/Jakarta'))->format('d M Y H:i') }}</td>
                        <td><a class="admin-button-secondary inline-flex" href="{{ route('admin.reviews.show', $review->id) }}">Periksa catatan</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-8 text-center">Tidak ada pemeriksaan yang menunggu.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        <div class="mt-4">{{ $reviews->links() }}</div>
    </section>
</div>
