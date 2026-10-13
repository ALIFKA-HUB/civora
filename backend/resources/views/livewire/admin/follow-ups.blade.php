<div>
    <div class="mb-6">
        <p class="admin-muted text-sm">Poin · Pendampingan</p>
        <h1 class="mt-1 text-2xl font-semibold md:text-[28px]">Tindak lanjut</h1>
        <p class="admin-muted mt-2">Catat langkah pendampingan untuk peringatan aktif. Status tindak lanjut tidak mengurangi total poin.</p>
    </div>
    @if ($message)<p role="status" class="admin-card mb-4 border-l-4 border-teal-700 p-4">{{ $message }}</p>@endif
    @if ($errors->has('reauthentication'))<p role="alert" class="admin-card mb-4 p-4">{{ $errors->first('reauthentication') }} <a class="admin-link-inline" href="{{ route('admin.confirm-password') }}">Konfirmasi password admin</a></p>@endif

    <section class="admin-card mb-6 p-5" aria-labelledby="follow-up-form-title">
        <h2 id="follow-up-form-title" class="text-lg font-semibold">Catat tindak lanjut</h2>
        @if ($latestPolicy)<p class="admin-muted mt-1 text-sm">Peringatan yang dapat dipilih berasal dari kebijakan versi {{ $latestPolicy->version }} untuk semester ini.</p>@else<p class="admin-muted mt-1 text-sm">Pilih semester dengan kebijakan ambang terbaru untuk melihat peringatan aktif.</p>@endif
        <form wire:submit="record" class="mt-4 grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div><label for="follow-up-semester" class="admin-label">Semester</label><select id="follow-up-semester" wire:model.live="semesterId" class="admin-control w-full"><option value="">Pilih semester</option>@foreach ($semesters as $item)<option value="{{ $item->id }}">{{ $item->name }} · {{ $item->academicYear->name }}</option>@endforeach</select>@error('semesterId')<p class="admin-error">{{ $message }}</p>@enderror</div>
            <div><label for="follow-up-alert" class="admin-label">Siswa dan peringatan</label><select id="follow-up-alert" wire:model="alertId" class="admin-control w-full"><option value="">Pilih peringatan aktif</option>@foreach ($alerts as $alert)<option value="{{ $alert->id }}">{{ $alert->student->user->name }} · {{ $alert->thresholdLevel->label }} ({{ $alert->thresholdLevel->boundary_points }} poin)</option>@endforeach</select>@error('alertId')<p class="admin-error">{{ $message }}</p>@enderror</div>
            <div><label for="follow-up-action" class="admin-label">Tindakan</label><input id="follow-up-action" wire:model="action" class="admin-control w-full" maxlength="1000">@error('action')<p class="admin-error">{{ $message }}</p>@enderror</div>
            <div><label for="follow-up-date" class="admin-label">Jadwal atau waktu pelaksanaan</label><input id="follow-up-date" wire:model="scheduledOrPerformedAt" type="datetime-local" class="admin-control w-full">@error('scheduledOrPerformedAt')<p class="admin-error">{{ $message }}</p>@enderror</div>
            <div><label for="follow-up-status" class="admin-label">Status</label><select id="follow-up-status" wire:model="status" class="admin-control w-full"><option value="planned">Direncanakan</option><option value="completed">Selesai</option><option value="cancelled">Dibatalkan</option></select>@error('status')<p class="admin-error">{{ $message }}</p>@enderror</div>
            <div><label for="follow-up-notes" class="admin-label">Catatan tambahan</label><input id="follow-up-notes" wire:model="notes" class="admin-control w-full" maxlength="5000">@error('notes')<p class="admin-error">{{ $message }}</p>@enderror</div>
            <button type="submit" class="admin-button-primary sm:col-span-2 lg:col-span-3" wire:confirm="Catat tindak lanjut ini? Statusnya tidak mengubah total poin siswa.">Simpan tindak lanjut</button>
        </form>
    </section>

    <section class="admin-card p-5" aria-labelledby="alert-list-title">
        <h2 id="alert-list-title" class="text-lg font-semibold">Peringatan aktif dan riwayat tindak lanjut</h2>
        @if ($latestPolicy)<p class="admin-muted mt-1 text-sm">Kebijakan terbaru versi {{ $latestPolicy->version }} · {{ $alerts->count() }} peringatan</p>
            <div class="mt-4 space-y-4">
                @forelse ($alerts as $alert)
                    <article class="border-b border-[var(--border)] pb-4 last:border-0" aria-labelledby="alert-{{ $alert->id }}">
                        <h3 id="alert-{{ $alert->id }}" class="font-semibold">{{ $alert->student->user->name }} · {{ $alert->thresholdLevel->label }} ({{ $alert->thresholdLevel->boundary_points }} poin)</h3>
                        <p class="admin-muted mt-1">{{ $alert->thresholdLevel->recommended_action }} · Peringatan aktif</p>
                        @if ($alert->followUps->isNotEmpty())
                            <ul class="mt-3 space-y-2">@foreach ($alert->followUps->sortByDesc('created_at') as $followUp)<li><span class="admin-status">{{ ['planned' => 'Direncanakan', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan'][$followUp->status] }}</span> {{ $followUp->action }} · {{ $followUp->scheduled_or_performed_at->timezone(config('app.school_timezone', 'Asia/Jakarta'))->format('d M Y H:i') }}<span class="admin-muted"> · dicatat oleh {{ $followUp->performedBy->name }}</span>@if ($followUp->notes)<p class="admin-muted text-sm">{{ $followUp->notes }}</p>@endif</li>@endforeach</ul>
                        @else
                            <p class="admin-muted mt-2 text-sm">Belum ada tindak lanjut.</p>
                        @endif
                    </article>
                @empty
                    <p class="admin-muted py-6 text-center">Tidak ada peringatan aktif pada kebijakan terbaru untuk semester ini.</p>
                @endforelse
            </div>
        @else
            <p class="admin-muted mt-3">Pilih semester dengan kebijakan ambang terbaru.</p>
        @endif
    </section>
</div>
