# Pengembangan backend Civora

Fondasi B-01 berada di `backend/`. `GET /api/v1/health` memberi respons tepat `{"status":"ok"}`: pemeriksaan HTTP aplikasi, bukan kesiapan database. Login, aksi akademik/poin, dan admin UI belum tersedia.

## Versi terverifikasi pada 12 Oktober 2026

| Komponen | Versi |
| --- | --- |
| PHP portable VS17 x64 NTS | 8.5.11 |
| Composer lokal | 2.10.3 |
| Node.js | 26.3.0 |
| PostgreSQL | 18.4 |
| Laravel | 13.35.0 |
| Sanctum | 4.3.3 |
| PHPUnit | 12.5.38 |

Laravel 13 mendukung PHP 8.3–8.5 dan perbaikan keamanan sampai 17 Maret 2028: [kebijakan resmi](https://laravel.com/framework/docs/13.x/releases). Dependensi terkunci melalui `composer.lock` dan `package-lock.json`.

PHP berasal dari [distribusi resmi](https://www.php.net/downloads.php?os=windows), paket `php-8.5.11-nts-Win32-vs17-x64.zip`. SHA256 diverifikasi sebelum ekstraksi:

```text
0ea96e0d2b9b737a6036f05cf4e95c49313faa6d0f27bd97edb2742503f0c043
```

Ekstensi lokal: curl, fileinfo, mbstring, openssl, pdo_pgsql, pgsql, zip, intl, gd, sodium. PHP Herd-lite yang sudah ada tidak mempunyai pdo_pgsql dan tidak diubah. Composer lama menampilkan deprecation pada PHP 8.5; salinan lokal diperbarui melalui self-update resmi.

## Setup Windows / PowerShell 7

Dari root repositori:

```powershell
./ops/dev/setup.ps1
# Opsional bila lokasi runtime berbeda:
./ops/dev/setup.ps1 -PgBin 'C:/Program Files/PostgreSQL/18/bin' -ComposerPhar 'C:/path/composer.phar' -Port 54329
npm --prefix backend ci --ignore-scripts
npm --prefix backend run build
./ops/dev/postgres.ps1 -Action status
./ops/dev/postgres.ps1 -Action stop
./ops/dev/postgres.ps1 -Action start
./ops/dev/php.ps1 artisan migrate
./ops/dev/php.ps1 artisan test
./ops/dev/php.ps1 artisan test --filter=DatabaseConstraintsTest
./ops/dev/php.ps1 artisan serve --host=127.0.0.1 --port=8000
```

Setup memverifikasi paket PHP, menyalin Composer ke `.runtime/`, dan membuat cluster baru `.runtime/pgsql`. Script menolak mengadopsi data directory tanpa marker Civora. Server hanya mendengarkan `127.0.0.1` pada port tinggi yang kosong; startup tersembunyi. Database `civora_dev` dan `civora_test` terpisah. Tidak ada perubahan pada PATH, service, PHP global, atau cluster PostgreSQL yang sudah ada.

Password acak dan APP_KEY hanya ditulis ke berkas lokal yang diabaikan Git: `.runtime/pg-password`, `backend/.env`, `backend/.env.testing`. Script tidak mencetaknya dan tidak menimpa env yang sudah ada. Jangan commit runtime, env privat, vendor, node_modules, log, atau build. `btree_gist` memerlukan hak CREATE EXTENSION; pada deployment siapkan melalui akun migrasi berwenang.

Wrapper PHP selalu berjalan dari `backend`. Setup manual pada sistem lain: sediakan PHP dengan ekstensi di atas, PostgreSQL dengan btree_gist, jalankan `composer install`, salin `.env.example` dan `.env.testing.example`, isi credential/APP_KEY, lalu jalankan migrasi. Test wajib memakai PostgreSQL dan database `civora_test`; guard memeriksa sebelum RefreshDatabase memigrasi. Database test sekali pakai dibangun ulang.

## Skema dan batas fondasi

Domain memakai UUID, NISN/NIP teks, dan timestamp PostgreSQL bertimezone. Koneksi database memakai UTC; aplikasi default Asia/Jakarta. Foreign key riwayat memakai RESTRICT. Seluruh entitas dokumen data, relasi Eloquent, constraint unik/check, partial unique semester aktif/review pending, exclusion rentang enrollment `[valid_from, valid_until)`, dan indeks riwayat tersedia.

`PointRecord::revisions()` menyimpan semua revisi; `latestRevision()` memilih revision_number terbesar melalui subquery berkorelasi. `ofMany()` bawaan mengagregasi UUID yang tidak didukung PostgreSQL. Bukti lama tetap direferensikan revisi lama.

Sanctum memakai token ID bigint bawaan dengan `tokenable_id` UUID. `qr_tokens.session_id` nullable bigint FK dengan ON DELETE SET NULL: pencabutan sesi mempertahankan metadata QR. B-06 wajib menolak QR bila issuing token hilang/dicabut. Session/cache/queue/failed jobs memakai tabel Laravel. TTL Sanctum 10.080 menit (7 hari), session idle 30 menit; alur autentikasi dan pemeriksaan akun aktif adalah B-02.

Aturan lintas tabel dijalankan di dalam transaksi pada B-02/B-03/B-07: kecocokan role/profil/alias, rentang enrollment di semester dan tahun kelas, kecocokan selection/record, konsumsi QR, serta append-only revisi. B-01 tidak menyediakan action mutasi tersebut. Status import dipilih `preview/confirmed/failed/cancelled`; export mengikuti rancangan admin `queued/processing/completed/failed`. Wadah JSON audit/outbox belum menyediakan filter aman; action/worker berikutnya harus menerapkannya. Disk local Laravel privat. Tidak ada seeder data sekolah/admin contoh.

Rollback hanya pada database test kosong:

```powershell
./ops/dev/php.ps1 artisan migrate:fresh --env=testing --force
./ops/dev/php.ps1 artisan migrate:rollback --env=testing --force
./ops/dev/php.ps1 artisan migrate --env=testing --force
./ops/dev/php.ps1 artisan migrate --env=testing --force
```

Rollback constraint menghapus hanya nama constraint/index migrasi tersebut. Extension btree_gist sengaja dipertahankan agar tidak menghapus extension yang dipakai objek lain.
