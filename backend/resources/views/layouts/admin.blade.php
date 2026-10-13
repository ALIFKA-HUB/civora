<!doctype html>
<html lang="id" data-theme="system">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Admin' }} · Civora</title>
    <script>
        (() => {
            const saved = localStorage.getItem('civora-theme') || 'system';
            document.documentElement.dataset.theme = saved;
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    @php
        $schoolName = \App\Models\SchoolSetting::query()->value('name') ?? 'Civora';
        $activeSemester = \App\Models\Semester::query()->where('status', 'active')->with('academicYear')->first();
        $adminUser = auth('web')->user();
        $adminReady = $adminUser?->role === 'admin' && $adminUser->account_status === 'active' && ! $adminUser->must_change_password;
    @endphp
    <div class="admin-shell" x-data="{
        navigationOpen: false,
        openNavigation() {
            this.navigationOpen = true;
            this.$nextTick(() => this.$refs.firstNavigationLink.focus());
        },
        closeNavigation() {
            this.navigationOpen = false;
            this.$nextTick(() => this.$refs.navigationTrigger.focus());
        }
    }" @keydown.escape.window="if (navigationOpen) closeNavigation()">
        <a :inert="navigationOpen" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded focus:bg-white focus:px-4 focus:py-3 focus:text-teal-900" href="#main-content">Lewati ke konten utama</a>
        @if ($adminReady)
            <div x-cloak x-show="navigationOpen" class="admin-drawer-backdrop md:hidden" @click="closeNavigation()"></div>
            <aside x-trap.inert.noscroll="navigationOpen" class="admin-sidebar admin-mobile-drawer fixed bottom-0 left-0 top-0 hidden w-64 flex-col md:flex" :class="navigationOpen ? '!flex' : 'max-md:hidden'" :role="navigationOpen ? 'dialog' : null" :aria-modal="navigationOpen ? 'true' : null" :aria-label="navigationOpen ? 'Navigasi utama' : null">
                <div class="border-b border-[var(--border)] px-6 py-5">
                    <a href="{{ route('admin.home') }}" class="text-xl font-semibold text-[var(--primary)] no-underline" x-ref="firstNavigationLink">Civora</a>
                    <p class="admin-muted mt-1 text-sm">Administrasi sekolah</p>
                </div>
                <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-5">
                    <section aria-labelledby="nav-summary">
                        <h2 id="nav-summary" class="admin-muted px-3 text-xs font-semibold uppercase tracking-wide">Ringkasan</h2>
                        <a href="{{ route('admin.home') }}" class="admin-link mt-2" @if (request()->routeIs('admin.home')) aria-current="page" @endif @click="closeNavigation()">Dashboard</a>
                    </section>
                    <section aria-labelledby="nav-school-data">
                        <h2 id="nav-school-data" class="admin-muted px-3 text-xs font-semibold uppercase tracking-wide">Data sekolah</h2>
                        <div class="mt-2 space-y-1">
                            <a href="{{ route('admin.students') }}" class="admin-link" @if (request()->routeIs('admin.students*')) aria-current="page" @endif @click="closeNavigation()">Siswa</a>
                            <a href="{{ route('admin.teachers') }}" class="admin-link" @if (request()->routeIs('admin.teachers')) aria-current="page" @endif @click="closeNavigation()">Guru</a>
                            <a href="{{ route('admin.academic') }}" class="admin-link" @if (request()->routeIs('admin.academic')) aria-current="page" @endif @click="closeNavigation()">Akademik</a>
                        </div>
                    </section>
                    <section aria-labelledby="nav-points">
                        <h2 id="nav-points" class="admin-muted px-3 text-xs font-semibold uppercase tracking-wide">Poin</h2>
                        <div class="mt-2 space-y-1">
                            <span class="admin-link admin-muted" aria-disabled="true">Daftar poin <span class="ml-auto text-xs">Belum tersedia</span></span>
                            <span class="admin-link admin-muted" aria-disabled="true">Catatan poin <span class="ml-auto text-xs">Belum tersedia</span></span>
                            <span class="admin-link admin-muted" aria-disabled="true">Pemeriksaan <span class="ml-auto text-xs">Belum tersedia</span></span>
                            <span class="admin-link admin-muted" aria-disabled="true">Ambang &amp; tindak lanjut <span class="ml-auto text-xs">Belum tersedia</span></span>
                        </div>
                    </section>
                    <section aria-labelledby="nav-administration">
                        <h2 id="nav-administration" class="admin-muted px-3 text-xs font-semibold uppercase tracking-wide">Administrasi</h2>
                        <div class="mt-2 space-y-1">
                            <a href="{{ route('admin.import') }}" class="admin-link" @if (request()->routeIs('admin.import*')) aria-current="page" @endif @click="closeNavigation()">Import akun</a>
                            <span class="admin-link admin-muted" aria-disabled="true">Laporan <span class="ml-auto text-xs">Belum tersedia</span></span>
                            <span class="admin-link admin-muted" aria-disabled="true">Pengaturan <span class="ml-auto text-xs">Belum tersedia</span></span>
                            <span class="admin-link admin-muted" aria-disabled="true">Audit <span class="ml-auto text-xs">Belum tersedia</span></span>
                        </div>
                    </section>
                </nav>
                <div class="border-t border-[var(--border)] p-4">
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button class="admin-button-secondary w-full" type="submit">Keluar</button>
                    </form>
                </div>
            </aside>
        @endif

        <div data-admin-background :inert="navigationOpen" class="{{ $adminReady ? 'md:pl-64' : '' }} min-h-screen">
            <header class="admin-header flex min-h-16 items-center justify-between gap-3 px-4 py-3 md:px-8">
                <div class="flex min-w-0 items-center gap-3">
                    @if ($adminReady)
                        <button type="button" class="admin-button-secondary md:hidden" aria-label="Buka navigasi" :aria-expanded="navigationOpen.toString()" x-ref="navigationTrigger" @click="openNavigation()">Menu</button>
                    @endif
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ $schoolName }}</p>
                        <p class="admin-muted truncate text-sm">{{ $activeSemester ? $activeSemester->name.' · '.$activeSemester->academicYear?->name : 'Belum ada semester aktif' }}</p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <label for="theme-select" class="sr-only">Tema tampilan</label>
                    <select id="theme-select" class="admin-control min-h-11 w-auto text-sm" data-theme-select aria-label="Tema tampilan">
                        <option value="system">Ikuti perangkat</option>
                        <option value="light">Terang</option>
                        <option value="dark">Gelap</option>
                    </select>
                    @if ($adminUser)
                        <span class="hidden max-w-40 truncate text-sm sm:inline">{{ $adminUser->name }}</span>
                    @endif
                </div>
            </header>
            <main id="main-content" class="mx-auto w-full max-w-7xl p-4 md:p-8" tabindex="-1">
                {{ $slot }}
            </main>
        </div>
    </div>
    @livewireScripts
</body>
</html>
