<div>
    <div class="mb-6">
        <p class="admin-muted text-sm">Ringkasan administrasi</p>
        <h1 class="mt-1 text-2xl font-semibold md:text-[28px]">Dashboard</h1>
        @if ($semester)
            <p class="admin-muted mt-2">Periode berjalan: {{ $semester->name }} · {{ $semester->academicYear?->name }}</p>
        @else
            <p class="admin-muted mt-2">Belum ada semester aktif. Ringkasan periode akan tersedia setelah semester dibuka.</p>
        @endif
    </div>

    <section aria-label="Ringkasan sekolah" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <article class="admin-card p-5 sm:p-6">
            <p class="admin-muted text-sm">Pemeriksaan menunggu</p>
            <p class="mt-3 text-3xl font-semibold tabular-nums">{{ number_format($pendingReviews, 0, ',', '.') }}</p>
            <p class="admin-muted mt-2 text-sm">Keberatan dan permintaan koreksi yang belum diputuskan.</p>
        </article>
        <article class="admin-card p-5 sm:p-6">
            <p class="admin-muted text-sm">Siswa mencapai ambang</p>
            <p class="mt-3 text-3xl font-semibold tabular-nums">{{ number_format($activeThresholds, 0, ',', '.') }}</p>
            <p class="admin-muted mt-2 text-sm">Status ambang aktif pada semester berjalan.</p>
        </article>
    </section>

    <section class="admin-card mt-6 p-5 sm:p-6" aria-labelledby="period-title">
        <h2 id="period-title" class="text-lg font-semibold">Ringkasan periode</h2>
        @if ($semester)
            <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div><dt class="admin-muted text-sm">Tahun ajaran</dt><dd class="mt-1 font-medium">{{ $semester->academicYear?->name ?? 'Belum ditetapkan' }}</dd></div>
                <div><dt class="admin-muted text-sm">Semester aktif</dt><dd class="mt-1 font-medium">{{ $semester->name }}</dd></div>
            </dl>
        @else
            <p class="admin-muted mt-3">Data periode akan muncul saat sekolah mengaktifkan semester.</p>
        @endif
    </section>
</div>
