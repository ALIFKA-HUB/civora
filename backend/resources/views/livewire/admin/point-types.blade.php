<div>
    <div class="mb-6">
        <p class="admin-muted text-sm">Poin · Administrasi katalog</p>
        <h1 class="mt-1 text-2xl font-semibold md:text-[28px]">Daftar poin</h1>
        <p class="admin-muted mt-2">Kelola jenis prestasi dan pelanggaran. Setiap revisi membuat versi baru; nilai pada catatan lama tidak berubah.</p>
    </div>
    @if ($message)<p role="status" class="admin-card mb-4 border-l-4 border-teal-700 p-4">{{ $message }}</p>@endif
    @if ($errors->has('reauthentication'))<p role="alert" class="admin-card mb-4 p-4">{{ $errors->first('reauthentication') }} <a class="admin-link-inline" href="{{ route('admin.confirm-password') }}">Konfirmasi password admin</a></p>@endif

    <section class="admin-card mb-6 p-5" aria-labelledby="catalog-form-title">
        <h2 id="catalog-form-title" class="text-lg font-semibold">{{ $targetTypeId ? 'Revisi jenis poin' : 'Tambah jenis poin' }}</h2>
        @if ($targetTypeId)<p class="admin-muted mt-1 text-sm">Kode {{ $code }} · Simpan akan menambahkan versi baru dan menjaga semua nilai versi lama.</p>@else<p class="admin-muted mt-1 text-sm">Nilai harus berupa bilangan positif. Konfirmasi admin diperlukan untuk menyimpan.</p>@endif
        <form wire:submit="{{ $targetTypeId ? 'revise' : 'create' }}" class="mt-4 grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div><label for="point-code" class="admin-label">Kode</label><input id="point-code" wire:model="code" class="admin-control w-full" @disabled($targetTypeId) autocomplete="off">@error('code')<p class="admin-error">{{ $message }}</p>@enderror</div>
            <div><label for="point-category" class="admin-label">Kategori</label><select id="point-category" wire:model="category" class="admin-control w-full"><option value="achievement">Prestasi</option><option value="violation">Pelanggaran</option></select>@error('category')<p class="admin-error">{{ $message }}</p>@enderror</div>
            <div><label for="point-name" class="admin-label">Nama jenis poin</label><input id="point-name" wire:model="name" class="admin-control w-full" autocomplete="off">@error('name')<p class="admin-error">{{ $message }}</p>@enderror</div>
            <div><label for="point-value" class="admin-label">Nilai poin</label><input id="point-value" wire:model="points" type="number" min="1" step="1" class="admin-control w-full">@error('points')<p class="admin-error">{{ $message }}</p>@enderror</div>
            <div class="sm:col-span-2"><label for="point-description" class="admin-label">Keterangan</label><textarea id="point-description" wire:model="description" rows="2" class="admin-control w-full"></textarea>@error('description')<p class="admin-error">{{ $message }}</p>@enderror</div>
            <div class="flex flex-wrap gap-3 sm:col-span-2 lg:col-span-3">
                @if ($targetTypeId)
                    <button type="submit" class="admin-button-primary" wire:confirm="Simpan revisi sebagai versi katalog baru?">Simpan revisi</button>
                    <button type="button" class="admin-button-secondary" wire:click="cancelRevision">Batal revisi</button>
                @else
                    <button type="submit" class="admin-button-primary">Tambah jenis poin</button>
                @endif
            </div>
        </form>
    </section>

    <section class="admin-card p-5" aria-labelledby="catalog-title">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div><h2 id="catalog-title" class="text-lg font-semibold">Katalog dan riwayat versi</h2><p class="admin-muted mt-1 text-sm">{{ $types->count() }} jenis poin · versi terbaru ditandai pada tiap jenis.</p></div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div><label for="catalog-search" class="admin-label">Cari kode atau nama</label><input id="catalog-search" wire:model.live.debounce.250ms="search" type="search" class="admin-control w-full"></div>
                <div><label for="catalog-category" class="admin-label">Kategori</label><select id="catalog-category" wire:model.live="categoryFilter" class="admin-control w-full"><option value="">Semua kategori</option><option value="achievement">Prestasi</option><option value="violation">Pelanggaran</option></select></div>
                <div><label for="catalog-status" class="admin-label">Status</label><select id="catalog-status" wire:model.live="statusFilter" class="admin-control w-full"><option value="">Semua status</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select></div>
            </div>
        </div>
        <div class="mt-4 overflow-x-auto"><table class="admin-table min-w-full">
            <caption class="sr-only">Daftar jenis poin aktif dan nonaktif beserta riwayat versi nilainya</caption>
            <thead><tr><th scope="col">Kode dan kategori</th><th scope="col">Nama dan nilai</th><th scope="col">Status</th><th scope="col">Riwayat versi</th><th scope="col">Aksi</th></tr></thead>
            <tbody>
                @forelse ($types as $type)
                    @php($latest = $type->pointTypeVersions->sortByDesc('version')->first())
                    <tr>
                        <td><strong>{{ $type->code }}</strong><br><span class="admin-muted">{{ $type->category === 'achievement' ? 'Prestasi' : 'Pelanggaran' }}</span></td>
                        <td>{{ $latest?->name }}<br><span class="admin-muted">{{ $latest?->points }} poin · Versi {{ $latest?->version }}</span></td>
                        <td><span class="admin-status">{{ $type->active ? 'Aktif' : 'Nonaktif' }}</span></td>
                        <td><details><summary class="admin-link-inline cursor-pointer">Lihat {{ $type->pointTypeVersions->count() }} versi</summary><ol class="mt-2 space-y-2 pl-5">
                            @foreach ($type->pointTypeVersions->sortByDesc('version') as $version)
                                <li><strong>Versi {{ $version->version }} · {{ $version->points }} poin</strong><br>{{ $version->name }}<br><span class="admin-muted">{{ $version->description }}</span></li>
                            @endforeach
                        </ol></details></td>
                        <td><div class="flex flex-wrap gap-2">
                            <button type="button" class="admin-button-secondary" wire:click="beginRevision('{{ $type->id }}')">Revisi</button>
                            @if ($type->active)<button type="button" class="admin-button-secondary" wire:click="deactivate('{{ $type->id }}')" wire:confirm="Nonaktifkan jenis poin ini? Versi dan catatan historis tetap tersedia.">Nonaktifkan</button>@endif
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-8 text-center">Belum ada jenis poin yang cocok dengan filter ini.</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </section>
</div>
