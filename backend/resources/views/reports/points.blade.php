<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 24mm 12mm 20mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #172b2b; }
        h1 { font-size: 17pt; margin: 0 0 4mm; }
        h2 { font-size: 11pt; margin: 6mm 0 2mm; }
        .meta { color: #405454; margin: 1mm 0; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        th, td { border: 0.3mm solid #8da5a3; padding: 1.5mm; vertical-align: top; overflow-wrap: break-word; }
        th { background: #e5f3f1; text-align: left; }
        .summary { width: 45%; }
        .summary td:last-child { text-align: right; }
        .details { font-size: 7pt; }
        .points { text-align: right; white-space: nowrap; }
        .description { white-space: pre-wrap; }
    </style>
</head>
<body>
    <h1>Laporan Poin — {{ $metadata['school'] }}</h1>
    <p class="meta">Semester: {{ $metadata['semester'] }} ({{ $metadata['semester_period'] }})</p>
    <p class="meta">Timezone: {{ $metadata['timezone'] }} · Dibuat: {{ $metadata['generated_at'] }}</p>
    <p class="meta">Filter: {{ $metadata['filters'] }}</p>

    <h2>Rekap</h2>
    <table class="summary">
        <thead><tr><th>Kategori</th><th>Total poin</th></tr></thead>
        <tbody>
            <tr><td>Prestasi</td><td>{{ $report['summary']['achievement'] }}</td></tr>
            <tr><td>Pelanggaran</td><td>{{ $report['summary']['violation'] }}</td></tr>
        </tbody>
    </table>

    <h2>Detail catatan</h2>
    <table class="details">
        <thead><tr><th>NISN</th><th>Nama siswa</th><th>Waktu</th><th>Kelas saat kejadian</th><th>Jurusan saat kejadian</th><th>Kategori</th><th>Jenis poin</th><th>Poin</th><th>Status</th><th>Keterangan</th></tr></thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td>{{ $row['nisn'] }}</td><td>{{ $row['student'] }}</td><td>{{ $row['occurred_at'] }}</td>
                    <td>{{ $row['class'] }}</td><td>{{ $row['major'] }}</td><td>{{ $row['category'] }}</td>
                    <td>{{ $row['point_name'] }}</td><td class="points">{{ $row['points'] }}</td>
                    <td>{{ $row['status'] }}</td><td class="description">{{ $row['description'] }}</td>
                </tr>
            @empty
                <tr><td colspan="10">Tidak ada catatan untuk filter ini.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
