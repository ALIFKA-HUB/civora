<div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div><p class="admin-muted text-sm">Data sekolah</p><h1 class="mt-1 text-2xl font-semibold md:text-[28px]">Siswa</h1><p class="admin-muted mt-2">Identitas, status sekolah, penempatan, dan bantuan akun.</p></div>
        <a href="{{ route('admin.import') }}" class="admin-button-secondary">Import siswa</a>
    </div>

    @if ($message)<p role="status" class="admin-card mb-4 border-l-4 border-teal-700 p-4">{{ $message }}</p>@endif

    <section class="admin-card mb-6 p-5" aria-labelledby="new-student-title">
        <h2 id="new-student-title" class="text-lg font-semibold">Tambah siswa</h2>
        <form wire:submit="create" class="mt-4 grid grid-cols-1 items-end gap-4 sm:grid-cols-3">
            <div><label for="student-name" class="admin-label">Nama lengkap</label><input id="student-name" wire:model="name" class="admin-control w-full" autocomplete="name" required>@error('name')<p class="admin-error">{{ $message }}</p>@enderror</div>
            <div><label for="student-nisn" class="admin-label">NISN</label><input id="student-nisn" wire:model="nisn" class="admin-control w-full" inputmode="numeric" autocomplete="off" required>@error('nisn')<p class="admin-error">{{ $message }}</p>@enderror</div>
            <button class="admin-button-primary" type="submit">Buat akun siswa</button>
        </form>
    </section>

    <section class="admin-card p-5" aria-labelledby="students-list-title">
        <div class="flex flex-wrap items-end justify-between gap-4"><div><h2 id="students-list-title" class="text-lg font-semibold">Daftar siswa</h2><p class="admin-muted mt-1 text-sm">{{ number_format($students->total(), 0, ',', '.') }} hasil</p></div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div><label for="student-search" class="admin-label">Cari nama atau NISN</label><input id="student-search" wire:model.live.debounce.300ms="search" class="admin-control w-full" type="search"></div>
                <div><label for="student-status" class="admin-label">Status</label><select id="student-status" wire:model.live="status" class="admin-control w-full"><option value="">Semua status</option><option value="active">Pendidikan aktif</option><option value="pending">Belum aktivasi</option><option value="inactive">Akun nonaktif</option><option value="transferred">Pindah</option><option value="graduated">Lulus</option></select></div>
            </div>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="admin-table min-w-full">
                <caption class="sr-only">Daftar siswa yang dapat dicari dan difilter</caption>
                <thead><tr><th scope="col">Siswa</th><th scope="col">NISN</th><th scope="col">Kelas terakhir</th><th scope="col">Status</th><th scope="col">Aksi</th></tr></thead>
                <tbody>
                    @forelse ($students as $student)
                        <tr>
                            <td><a class="admin-link-inline font-medium" href="{{ route('admin.students.show', $student) }}">{{ $student->user->name }}</a></td>
                            <td class="font-mono">{{ $student->nisn }}</td>
                            <td>{{ $student->enrollments->first()?->classroom?->name ?? 'Belum ditempatkan' }}</td>
                            <td><span class="admin-status">{{ $student->education_status }} · {{ $student->user->account_status === 'pending_activation' ? 'belum aktivasi' : $student->user->account_status }}</span></td>
                            <td><a class="admin-link-inline" href="{{ route('admin.students.show', $student) }}">Lihat detail</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><div role="status" class="py-8 text-center"><p class="font-medium">Tidak ada siswa yang cocok</p><p class="admin-muted mt-1 text-sm">Ubah pencarian atau filter untuk melihat hasil lain.</p></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $students->links() }}</div>
    </section>
</div>
