<section class="mx-auto max-w-xl py-8" aria-labelledby="confirm-title">
    <div class="admin-card p-6 sm:p-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-[var(--primary)]">Keamanan akun</p>
        <h1 id="confirm-title" class="mt-3 text-2xl font-semibold">Konfirmasi password</h1>
        <p class="admin-muted mt-2">Masukkan password admin saat ini untuk melanjutkan perubahan administratif.</p>
        <form wire:submit="confirm" class="mt-6 space-y-5">
            <div>
                <label for="current-password" class="mb-2 block font-medium">Password saat ini</label>
                <input id="current-password" class="admin-control" type="password" wire:model="password" autocomplete="current-password" required autofocus>
                @error('password') <p class="admin-field-error mt-2" role="alert">{{ $message }}</p> @enderror
            </div>
            <button class="admin-button w-full" type="submit">Konfirmasi</button>
        </form>
    </div>
</section>
