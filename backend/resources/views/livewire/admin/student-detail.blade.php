<div>
    <div class="mb-6"><a href="{{ route('admin.students') }}" class="admin-link-inline">← Kembali ke siswa</a><p class="admin-muted mt-4 text-sm">Data sekolah · Detail siswa</p><h1 class="mt-1 text-2xl font-semibold md:text-[28px]">{{ $student->user->name }}</h1><p class="admin-muted mt-2">NISN <span class="font-mono">{{ $student->nisn }}</span> · Pendidikan {{ $student->education_status }} · Akun {{ $student->user->account_status }}</p></div>
    @if ($message)<p role="status" class="admin-card mb-4 border-l-4 border-teal-700 p-4">{{ $message }}</p>@endif
    @if ($errors->has('reauthentication'))<p role="alert" class="admin-card mb-4 p-4">{{ $errors->first('reauthentication') }} <a class="admin-link-inline" href="{{ route('admin.confirm-password') }}">Konfirmasi password admin</a></p>@endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
        <div class="space-y-5">
            <section class="admin-card p-5" aria-labelledby="placement-history-title"><h2 id="placement-history-title" class="text-lg font-semibold">Riwayat kelas</h2>
                <div class="mt-4 overflow-x-auto"><table class="admin-table min-w-full"><thead><tr><th scope="col">Kelas</th><th scope="col">Jurusan</th><th scope="col">Semester</th><th scope="col">Mulai</th><th scope="col">Selesai</th></tr></thead><tbody>
                    @forelse ($student->enrollments as $enrollment)<tr><td>{{ $enrollment->classroom->name }}</td><td>{{ $enrollment->classroom->major->name }}</td><td>{{ $enrollment->semester->name }} · {{ $enrollment->semester->academicYear->name }}</td><td>{{ $enrollment->valid_from->timezone(config('app.school_timezone', 'Asia/Jakarta'))->format('d M Y H:i') }}</td><td>{{ $enrollment->valid_until?->timezone(config('app.school_timezone', 'Asia/Jakarta'))->format('d M Y H:i') ?? 'Berjalan' }}</td></tr>
                    @empty<tr><td colspan="5" class="py-6 text-center">Belum ada penempatan kelas.</td></tr>@endforelse
                </tbody></table></div>
            </section>

            <section class="admin-card p-5" aria-labelledby="records-title"><h2 id="records-title" class="text-lg font-semibold">Riwayat catatan</h2>
                <div class="mt-4 overflow-x-auto"><table class="admin-table min-w-full"><thead><tr><th scope="col">Waktu</th><th scope="col">Catatan</th><th scope="col">Kategori</th><th scope="col">Nilai</th><th scope="col">Guru</th></tr></thead><tbody>
                    @forelse ($records as $record)<tr><td>{{ $record->occurred_at->timezone(config('app.school_timezone', 'Asia/Jakarta'))->format('d M Y H:i') }}</td><td>{{ $record->latestRevision?->name_snapshot ?? 'Catatan tersimpan' }}</td><td>{{ $record->latestRevision?->category_snapshot ?? '—' }}</td><td>{{ $record->latestRevision?->points_snapshot ?? '—' }}</td><td>{{ $record->teacher?->user?->name ?? '—' }}</td></tr>
                    @empty<tr><td colspan="5" class="py-6 text-center">Belum ada catatan.</td></tr>@endforelse
                </tbody></table></div>
            </section>

            <section class="admin-card p-5" aria-labelledby="move-title"><h2 id="move-title" class="text-lg font-semibold">Pindah kelas</h2><p class="admin-muted mt-1 text-sm">Perpindahan menggunakan waktu efektif. Catatan lama tetap pada penempatan asal.</p>
                <form wire:submit="move" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3"><div><label for="destination-class" class="admin-label">Kelas tujuan</label><select id="destination-class" wire:model="destinationClassroomId" class="admin-control w-full"><option value="">Pilih kelas aktif</option>@foreach ($classrooms as $classroom)<option value="{{ $classroom->id }}">{{ $classroom->name }} · {{ $classroom->major->code }}</option>@endforeach</select>@error('destinationClassroomId')<p class="admin-error">{{ $message }}</p>@enderror</div><div><label for="effective-at" class="admin-label">Waktu efektif</label><input id="effective-at" wire:model="effectiveAt" class="admin-control w-full" type="datetime-local">@error('effectiveAt')<p class="admin-error">{{ $message }}</p>@enderror</div><div class="flex items-end"><button class="admin-button-primary w-full" type="submit">Simpan perpindahan</button></div></form>
            </section>
        </div>

        <aside class="space-y-5">
            <section class="admin-card p-5" aria-labelledby="account-actions-title"><h2 id="account-actions-title" class="text-lg font-semibold">Bantuan akun</h2>
                <p class="admin-muted mt-2 text-sm">Reset dan penonaktifan memerlukan konfirmasi password terbaru serta alasan 5–1.000 karakter.</p>
                <label for="account-reason" class="admin-label mt-4">Alasan tindakan</label><textarea id="account-reason" wire:model="reason" class="admin-control min-h-24 w-full" maxlength="1000"></textarea>@error('reason')<p class="admin-error">{{ $message }}</p>@enderror
                <div class="mt-3 flex flex-wrap gap-2"><button class="admin-button-secondary" type="button" wire:click="resetPassword">Reset password</button><button class="admin-button-danger" type="button" wire:click="deactivate">Nonaktifkan akun</button></div>
                <button class="admin-link-inline mt-4" type="button" wire:click="revealTemporaryCredential">Tampilkan password sementara</button>
                @error('credential')<p role="alert" class="admin-error">{{ $message }}</p>@enderror
                @if ($temporaryPassword)<div class="mt-3 rounded border border-[var(--border)] p-3"><p class="text-sm font-semibold">Password sementara</p><code data-credential-display class="mt-1 block break-all">{{ $temporaryPassword }}</code><button type="button" class="admin-link-inline mt-2" wire:click="hideTemporaryCredential">Tutup tampilan</button></div>@endif
                @error('reauthentication')<p role="alert" class="admin-error mt-3">{{ $message }} <a href="{{ route('admin.confirm-password') }}" class="admin-link-inline">Konfirmasi sekarang</a></p>@enderror
            </section>
            <section class="admin-card p-5" aria-labelledby="status-title"><h2 id="status-title" class="text-lg font-semibold">Status pendidikan</h2><p class="admin-muted mt-2 text-sm">Lulus atau pindah akan mengakhiri penempatan yang berjalan, menonaktifkan akun, dan menyimpan audit.</p>
                <label for="status-reason" class="admin-label mt-4">Alasan</label><textarea id="status-reason" wire:model="statusReason" class="admin-control min-h-24 w-full" maxlength="1000"></textarea>@error('statusReason')<p class="admin-error">{{ $message }}</p>@enderror
                <div class="mt-3 flex flex-wrap gap-2"><button class="admin-button-secondary" type="button" wire:click="graduate">Tandai lulus</button><button class="admin-button-secondary" type="button" wire:click="transfer">Tandai pindah</button></div>
            </section>
        </aside>
    </div>
</div>
