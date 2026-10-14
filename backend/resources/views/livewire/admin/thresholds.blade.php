<div>
    <div class="mb-6">
        <p class="admin-muted text-sm">Poin · Kebijakan semester</p>
        <h1 class="mt-1 text-2xl font-semibold md:text-[28px]">Ambang poin</h1>
        <p class="admin-muted mt-2">Atur tingkat ambang pelanggaran per semester. Mengganti kebijakan membuat versi baru dan peringatan aktif mengikuti versi terbaru.</p>
    </div>
    @if ($message)<p role="status" class="admin-card mb-4 border-l-4 border-teal-700 p-4">{{ $message }}</p>@endif
    @if ($errors->has('reauthentication'))<p role="alert" class="admin-card mb-4 p-4">{{ $errors->first('reauthentication') }} <a class="admin-link-inline" href="{{ route('admin.confirm-password') }}">Konfirmasi password admin</a></p>@endif

    <section class="admin-card mb-6 p-5" aria-labelledby="policy-form-title">
        <h2 id="policy-form-title" class="text-lg font-semibold">Kebijakan ambang baru</h2>
        <p class="admin-muted mt-1 text-sm">Tingkat lama disimpan sebagai riwayat. Nilai ambang harus positif dan tidak boleh berulang.</p>
        <form wire:submit="save" class="mt-4 space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div><label for="threshold-semester" class="admin-label">Semester</label><select id="threshold-semester" wire:model.live="semesterId" class="admin-control w-full"><option value="">Pilih semester</option>@foreach ($semesters as $item)<option value="{{ $item->id }}">{{ $item->name }} · {{ $item->academicYear->name }}</option>@endforeach</select>@error('semesterId')<p class="admin-error">{{ $message }}</p>@enderror</div>
                <div><label for="threshold-reason" class="admin-label">Alasan perubahan</label><input id="threshold-reason" wire:model="reason" class="admin-control w-full" maxlength="1000">@error('reason')<p class="admin-error">{{ $message }}</p>@enderror</div>
            </div>
            <fieldset class="space-y-3">
                <legend class="admin-label">Tingkat ambang</legend>
                @foreach ($levels as $index => $level)
                    <div wire:key="threshold-level-{{ $index }}" class="grid grid-cols-1 items-end gap-3 rounded-lg border border-[var(--border)] p-4 sm:grid-cols-[minmax(8rem,0.7fr)_minmax(10rem,1fr)_minmax(14rem,2fr)_auto]">
                        <div><label for="threshold-boundary-{{ $index }}" class="admin-label">Nilai ambang</label><input id="threshold-boundary-{{ $index }}" wire:model="levels.{{ $index }}.boundary_points" type="number" min="1" step="1" class="admin-control w-full">@error("levels.$index.boundary_points")<p class="admin-error">{{ $message }}</p>@enderror</div>
                        <div><label for="threshold-label-{{ $index }}" class="admin-label">Nama tingkat</label><input id="threshold-label-{{ $index }}" wire:model="levels.{{ $index }}.label" class="admin-control w-full">@error("levels.$index.label")<p class="admin-error">{{ $message }}</p>@enderror</div>
                        <div><label for="threshold-action-{{ $index }}" class="admin-label">Tindakan yang disarankan</label><input id="threshold-action-{{ $index }}" wire:model="levels.{{ $index }}.recommended_action" class="admin-control w-full">@error("levels.$index.recommended_action")<p class="admin-error">{{ $message }}</p>@enderror</div>
                        @if (count($levels) > 1)<button type="button" class="admin-button-secondary" wire:click="removeLevel({{ $index }})" aria-label="Hapus tingkat {{ $index + 1 }}">Hapus</button>@endif
                    </div>
                @endforeach
                @error('levels')<p class="admin-error">{{ $message }}</p>@enderror
                <button type="button" class="admin-button-secondary" wire:click="addLevel">Tambah tingkat</button>
            </fieldset>
            <button type="submit" class="admin-button-primary" wire:confirm="Simpan kebijakan ambang baru untuk semester ini? Kebijakan lama akan tetap tercatat.">Simpan kebijakan</button>
        </form>
    </section>

    <section class="admin-card mb-6 p-5" aria-labelledby="warnings-title">
        <h2 id="warnings-title" class="text-lg font-semibold">Peringatan dari kebijakan terbaru</h2>
        @if ($latestPolicy)
            <p class="admin-muted mt-1 text-sm">{{ $semester->name }} · Kebijakan versi {{ $latestPolicy->version }} · {{ $alerts->count() }} peringatan aktif</p>
            <div class="mt-4 overflow-x-auto"><table class="admin-table min-w-full"><caption class="sr-only">Siswa yang mencapai ambang berdasarkan kebijakan terbaru</caption><thead><tr><th scope="col">Siswa</th><th scope="col">Tingkat</th><th scope="col">Tindakan yang disarankan</th><th scope="col">Tercapai</th><th scope="col">Tindak lanjut</th></tr></thead><tbody>
                @forelse ($alerts as $alert)
                    <tr><td>{{ $alert->student->user->name }}<br><span class="admin-muted">{{ $alert->student->nisn }}</span></td><td>{{ $alert->thresholdLevel->label }} · {{ $alert->thresholdLevel->boundary_points }} poin</td><td>{{ $alert->thresholdLevel->recommended_action }}</td><td><span class="admin-status">Aktif</span><br>{{ $alert->reached_at->timezone(config('app.school_timezone', 'Asia/Jakarta'))->format('d M Y H:i') }}</td><td>{{ $alert->followUps->count() }} catatan</td></tr>
                @empty
                    <tr><td colspan="5" class="py-8 text-center">Belum ada siswa yang mencapai tingkat pada kebijakan terbaru.</td></tr>
                @endforelse
            </tbody></table></div>
        @else
            <p class="admin-muted mt-3">Pilih semester yang telah memiliki kebijakan ambang.</p>
        @endif
    </section>

    <section class="admin-card p-5" aria-labelledby="policy-history-title">
        <h2 id="policy-history-title" class="text-lg font-semibold">Riwayat kebijakan</h2>
        @if ($policies->isEmpty())<p class="admin-muted mt-3">Belum ada kebijakan ambang untuk semester yang dipilih.</p>@else
            <ol class="mt-4 space-y-4">
                @foreach ($policies as $policy)
                    <li class="border-l-2 border-[var(--primary)] pl-4"><h3 class="font-semibold">Versi {{ $policy->version }}{{ $policy->id === $latestPolicy?->id ? ' · Terbaru' : '' }}</h3><p class="admin-muted mt-1 text-sm">{{ $policy->created_at->timezone(config('app.school_timezone', 'Asia/Jakarta'))->format('d M Y H:i') }} · {{ $policy->reason }}</p><ul class="mt-2 flex flex-wrap gap-x-5 gap-y-1">@foreach ($policy->thresholdLevels->sortBy('boundary_points') as $threshold)<li>{{ $threshold->boundary_points }} poin · {{ $threshold->label }} — {{ $threshold->recommended_action }}</li>@endforeach</ul></li>
                @endforeach
            </ol>
        @endif
    </section>
</div>
