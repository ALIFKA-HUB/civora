<section class="mx-auto max-w-xl py-8" aria-labelledby="activation-title">
    <div class="admin-card p-6 sm:p-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-[var(--primary)]">Aktivasi akun admin</p>
        <h1 id="activation-title" class="mt-3 text-2xl font-semibold">Buat password pribadi</h1>
        <p class="admin-muted mt-2">Akun {{ auth('web')->user()->name }} hanya dapat melihat identitas, mengganti password, atau keluar sampai aktivasi selesai.</p>
        <form wire:submit="activate" class="mt-6 space-y-5">
            <div>
                <label for="new-password" class="mb-2 block font-medium">Password baru</label>
                <input id="new-password" class="admin-control" type="password" wire:model="password" autocomplete="new-password" minlength="12" required>
                @error('password') <p class="admin-field-error mt-2" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password-confirmation" class="mb-2 block font-medium">Ulangi password baru</label>
                <input id="password-confirmation" class="admin-control" type="password" wire:model="password_confirmation" autocomplete="new-password" minlength="12" required>
            </div>
            <button class="admin-button w-full" type="submit">Simpan password dan aktivasi</button>
        </form>
        <form method="POST" action="{{ route('admin.logout') }}" class="mt-4">
            @csrf
            <button class="admin-button-secondary w-full" type="submit">Keluar</button>
        </form>
    </div>
</section>
