<div>
    <div class="mb-6">
        <p class="admin-muted text-sm"><a class="admin-link-inline" href="{{ route('admin.reviews') }}">Pemeriksaan</a> · Detail</p>
        <h1 class="mt-1 text-2xl font-semibold md:text-[28px]">Detail pemeriksaan</h1>
        <p class="admin-muted mt-2">Catatan #{{ substr($record->id, 0, 8) }} · <span class="admin-status">{{ match ($review->status) { 'pending' => 'Menunggu pemeriksaan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', default => $review->status } }}</span></p>
    </div>
    @if ($message)<p role="status" class="admin-card mb-4 border-l-4 border-teal-700 p-4">{{ $message }}</p>@endif
    @if ($errors->has('reauthentication'))<p role="alert" class="admin-card mb-4 p-4">{{ $errors->first('reauthentication') }} <a class="admin-link-inline" href="{{ route('admin.confirm-password') }}">Konfirmasi password admin</a></p>@endif
    @if ($errors->has('revision'))<p role="alert" class="admin-card mb-4 p-4">{{ $errors->first('revision') }} <a class="admin-link-inline" href="{{ route('admin.reviews.show', $review->id) }}">Muat ulang pemeriksaan</a></p>@endif
    <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,0.8fr)]">
        <div class="space-y-5">
            <section class="admin-card p-5" aria-labelledby="request-title">
                <h2 id="request-title" class="text-lg font-semibold">Alasan dan pemohon</h2>
                <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><dt class="admin-muted text-sm">Jenis permintaan</dt><dd class="mt-1 font-medium">{{ $review->kind === 'student_objection' ? 'Keberatan siswa' : 'Koreksi guru' }}</dd></div>
                    <div><dt class="admin-muted text-sm">Pemohon</dt><dd class="mt-1 font-medium">{{ $review->requester->name }} · {{ $review->kind === 'student_objection' ? 'Siswa' : 'Guru' }}</dd></div>
                    <div><dt class="admin-muted text-sm">Siswa pada catatan</dt><dd class="mt-1 font-medium">{{ $record->student->user->name }} · NISN {{ $record->student->nisn }}</dd></div>
                    <div><dt class="admin-muted text-sm">Guru pencatat</dt><dd class="mt-1 font-medium">{{ $record->teacher->user->name }}</dd></div>
                    <div><dt class="admin-muted text-sm">Semester dan kelas saat dicatat</dt><dd class="mt-1 font-medium">{{ $record->semester->name }} · {{ $record->class_snapshot }}</dd></div>
                    <div><dt class="admin-muted text-sm">Versi catatan saat layar dibuka</dt><dd class="mt-1 font-medium">{{ $expectedRevision }}</dd></div>
                </dl>
                <div class="mt-4 rounded-md border border-[var(--border)] p-4"><h3 class="font-semibold">Alasan pemohon</h3><p class="mt-2 whitespace-pre-wrap">{{ $review->reason }}</p></div>
            </section>

            <section class="admin-card p-5" aria-labelledby="snapshots-title">
                <h2 id="snapshots-title" class="text-lg font-semibold">Perubahan catatan</h2>
                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div class="rounded-md border border-[var(--border)] p-4"><h3 class="font-semibold">Sebelum pemeriksaan</h3>
                        @if ($before)<dl class="mt-3 space-y-2 text-sm"><div><dt class="admin-muted">Jenis poin</dt><dd>{{ $before->name_snapshot }} · {{ $before->points_snapshot }} poin</dd></div><div><dt class="admin-muted">Keterangan</dt><dd class="whitespace-pre-wrap">{{ $before->description }}</dd></div><div><dt class="admin-muted">Status catatan</dt><dd>{{ $before->status === 'active' ? 'Berlaku' : 'Dibatalkan' }}</dd></div></dl>@endif
                    </div>
                    <div class="rounded-md border border-[var(--border)] p-4"><h3 class="font-semibold">Sesudah keputusan</h3>
                        @if ($decision === 'reject')
                            @if ($review->proposed_change)<p class="admin-muted mt-1 text-sm">Usulan pemohon tidak diterapkan.</p>@endif
                            <p class="mt-3">Catatan tetap seperti saat ini karena permintaan ditolak.</p>
                            @if ($current)<dl class="mt-3 space-y-2 text-sm"><div><dt class="admin-muted">Jenis poin</dt><dd>{{ $current->name_snapshot }} · {{ $current->points_snapshot }} poin</dd></div><div><dt class="admin-muted">Keterangan</dt><dd class="whitespace-pre-wrap">{{ $current->description }}</dd></div><div><dt class="admin-muted">Status catatan</dt><dd>Status catatan: {{ $current->status === 'active' ? 'Berlaku' : 'Dibatalkan' }}</dd></div></dl>@endif
                        @else
                            @if ($review->proposed_change)<p class="admin-muted mt-1 text-sm">Usulan: {{ $review->proposed_change['operation'] === 'cancel' ? 'pembatalan' : 'koreksi' }}</p>@endif
                            @if ($proposalVersion)<p class="mt-2 text-sm">Jenis poin yang diusulkan: {{ $proposalVersion->name }} · {{ $proposalVersion->points }} poin · versi {{ $proposalVersion->version }}@unless ($proposalVersion->pointType->active)<span class="admin-status">Nonaktif</span>@endunless</p>@endif
                            @if ($operation === 'cancel')<p class="mt-3">Status catatan menjadi dibatalkan. Pembatalan tidak memindahkan siswa.</p>@else
                                <p class="mt-3">{{ $versions->firstWhere('id', $pointTypeVersionId)?->name ?? $current?->name_snapshot }} · {{ $versions->firstWhere('id', $pointTypeVersionId)?->points ?? $current?->points_snapshot }} poin</p>
                                <p class="mt-2 whitespace-pre-wrap">{{ $description }}</p>
                                <p class="admin-muted mt-2 text-sm">Status catatan tetap berlaku sampai keputusan disimpan.</p>
                            @endif
                        @endif
                    </div>
                </div>
                @if ($current?->attachment_id)<div class="mt-4"><h3 class="font-semibold">Bukti saat ini</h3><a class="admin-link-inline" href="{{ route('admin.attachments.show', $current->attachment_id) }}" target="_blank" rel="noopener">Buka bukti privat</a><img class="mt-3 max-h-80 max-w-full rounded-md border border-[var(--border)] object-contain" src="{{ route('admin.attachments.show', $current->attachment_id) }}" alt="Bukti gambar privat untuk catatan"></div>@endif
                @if ($review->proposed_change && ($review->proposed_change['attachment_id'] ?? null))<p class="mt-3"><a class="admin-link-inline" href="{{ route('admin.attachments.show', $review->proposed_change['attachment_id']) }}" target="_blank" rel="noopener">Buka bukti usulan privat</a></p>@endif
            </section>
        </div>

        <section class="admin-card h-fit p-5" aria-labelledby="decision-title">
            <h2 id="decision-title" class="text-lg font-semibold">Keputusan</h2>
            @if ($review->status === 'pending')
                <form wire:submit="decide" class="mt-4 space-y-4">
                    <div><label for="review-decision" class="admin-label">Pilihan keputusan</label><select id="review-decision" wire:model.live="decision" class="admin-control w-full"><option value="approve">Setujui perubahan / keberatan</option><option value="reject">Tolak permintaan</option></select>@error('decision')<p class="admin-error">{{ $message }}</p>@enderror</div>
                    @if ($decision === 'approve')
                        <div><label for="review-operation" class="admin-label">Perubahan catatan</label><select id="review-operation" wire:model.live="operation" class="admin-control w-full"><option value="revise">Koreksi catatan</option><option value="cancel">Batalkan catatan</option></select>@error('operation')<p class="admin-error">{{ $message }}</p>@enderror</div>
                        @if ($operation === 'revise')
                            <div><label for="review-point-type" class="admin-label">Jenis poin dan nilai terbaru</label><select id="review-point-type" wire:model="pointTypeVersionId" class="admin-control w-full"><option value="">Pertahankan jenis poin saat ini</option>@foreach ($versions as $version)<option value="{{ $version->id }}">{{ $version->name }} · {{ $version->points }} poin · versi {{ $version->version }}</option>@endforeach</select><p class="admin-muted mt-1 text-sm">Pilihan memakai versi katalog terbaru saat keputusan disimpan.</p>@error('pointTypeVersionId')<p class="admin-error">{{ $message }}</p>@enderror</div>
                            <div><label for="review-description" class="admin-label">Keterangan sesudah keputusan</label><textarea id="review-description" wire:model="description" rows="4" class="admin-control w-full"></textarea>@error('description')<p class="admin-error">{{ $message }}</p>@enderror</div>
                            <div><label for="review-attachment" class="admin-label">Bukti privat</label><select id="review-attachment" wire:model="attachmentId" class="admin-control w-full"><option value="">Pertahankan bukti saat ini</option>@foreach ($attachments as $attachment)<option value="{{ $attachment->id }}">{{ $attachment->mime_type }} · {{ number_format($attachment->size / 1024, 0) }} KB</option>@endforeach</select>@error('attachmentId')<p class="admin-error">{{ $message }}</p>@enderror</div>
                        @else
                            <p class="rounded-md border border-[var(--border)] p-3 text-sm">Pembatalan tidak memindahkan siswa, guru pencatat, penempatan, atau semester.</p>
                        @endif
                    @endif
                    <div><label for="review-decision-reason" class="admin-label">Alasan keputusan</label><textarea id="review-decision-reason" wire:model="decisionReason" rows="3" required minlength="5" maxlength="1000" class="admin-control w-full"></textarea>@error('decisionReason')<p class="admin-error">{{ $message }}</p>@enderror</div>
                    <button type="submit" class="admin-button-primary" wire:confirm="Simpan keputusan pemeriksaan ini?">Simpan keputusan</button>
                </form>
            @else
                <p class="admin-muted mt-3">Permintaan ini sudah {{ $review->status === 'approved' ? 'disetujui' : 'ditolak' }}.</p><p class="mt-2">{{ $review->decision_reason }}</p>
            @endif
        </section>
    </div>
</div>
