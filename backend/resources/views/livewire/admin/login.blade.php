<section class="mx-auto flex min-h-[65vh] max-w-md items-center py-8" aria-labelledby="login-title">
    <div class="admin-card w-full p-6 sm:p-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-[var(--primary)]">Civora · Admin</p>
        <h1 id="login-title" class="mt-3 text-2xl font-semibold">Masuk ke administrasi</h1>
        <p class="admin-muted mt-2">Gunakan username admin yang diberikan sekolah.</p>
        <form wire:submit="authenticate" class="mt-6 space-y-5">
            <div>
                <label for="username" class="mb-2 block font-medium">Username</label>
                <input id="username" class="admin-control" type="text" wire:model="username" autocomplete="username" required autofocus>
                @error('username') <p class="admin-field-error mt-2" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password" class="mb-2 block font-medium">Password</label>
                <input id="password" class="admin-control" type="password" wire:model="password" autocomplete="current-password" required>
                @error('password') <p class="admin-field-error mt-2" role="alert">{{ $message }}</p> @enderror
            </div>
            <button class="admin-button w-full" type="submit" wire:loading.attr="disabled" wire:target="authenticate">
                <span wire:loading.remove wire:target="authenticate">Masuk</span>
                <span wire:loading wire:target="authenticate">Memeriksa…</span>
            </button>
        </form>
        <p class="admin-muted mt-6 text-sm">Jika password sementara kedaluwarsa, hubungi admin sekolah lain.</p>
    </div>
</section>
