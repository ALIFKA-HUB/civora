# Pengembangan backend Civora

Fondasi B-01, autentikasi B-02, action akademik B-03, dan katalog berversi B-04 berada di `backend/`. `GET /api/v1/health` memberi respons tepat `{"status":"ok"}`: pemeriksaan HTTP aplikasi, bukan kesiapan database. Login siswa/guru, profil, aktivasi/ganti password, logout, bantuan password sementara siswa, action akun/akademik/katalog, daftar jenis poin aktif untuk guru, dan policy resource tersedia. Shell login, aktivasi awal, dashboard read-only, dan sesi admin A-01 tersedia; pencatatan poin dan halaman pengelolaan admin berikutnya belum tersedia.

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

## Browser test admin (Laravel Dusk)

A-01 dan A-05 menggunakan PHPUnit + Laravel Dusk untuk pengujian UI Chromium sungguhan; tes browser berada di `backend/tests/Browser`. Dusk adalah runner browser yang dipilih untuk proyek ini karena PHPUnit sudah dipakai. Ikuti [panduan resmi Laravel Dusk](https://laravel.com/framework/docs/13.x/dusk) dan [Chrome for Testing](https://github.com/GoogleChromeLabs/chrome-for-testing) untuk versi driver.

Setelah setup backend dan `npm ci` di atas, siapkan file environment lokal yang diabaikan Git dari environment test milik workspace. Ini mempertahankan APP_KEY/password PostgreSQL yang dibuat setup, memastikan target disposable bernama `civora_test`, dan mengarahkan browser ke loopback:

```powershell
Copy-Item backend/.env.testing backend/.env.dusk.local -Force
$duskEnv = Join-Path (Get-Location) 'backend/.env.dusk.local'
$contents = Get-Content -LiteralPath $duskEnv -Raw
$contents = [regex]::Replace($contents, '(?m)^APP_URL=.*$', 'APP_URL=http://127.0.0.1:8031')
$contents = [regex]::Replace($contents, '(?m)^DB_DATABASE=.*$', 'DB_DATABASE=civora_test')
$contents = [regex]::Replace($contents, '(?m)^DB_HOST=.*$', 'DB_HOST=127.0.0.1')
[System.IO.File]::WriteAllText($duskEnv, $contents, [System.Text.UTF8Encoding]::new($false))
```

Pasang ChromeDriver yang cocok dengan Chrome terpasang memakai pendeteksi versi Dusk:

```powershell
./ops/dev/php.ps1 artisan dusk:chrome-driver --detect
```

Jika unduhan PHP cURL gagal memverifikasi sertifikat TLS di Windows, jangan mematikan verifikasi. Ambil build Chrome for Testing resmi yang sama melalui validasi sertifikat Windows oleh PowerShell. Respons `latest-patch-versions-per-build-with-downloads.json` memakai tiga bagian pertama versi Chrome sebagai key; pilih URL `chromedriver` untuk `win64`. Fallback ini hanya menulis ke `.runtime/` dan direktori vendor Composer proyek yang diabaikan Git:

```powershell
$chromeCandidates = @(
    "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
    "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
)
$chrome = $chromeCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
if (!$chrome) { throw 'Install Google Chrome before running Dusk.' }
$parts = (Get-Item -LiteralPath $chrome).VersionInfo.ProductVersion.Split('.')
$build = $parts[0..2] -join '.'
$metadata = Invoke-RestMethod 'https://googlechromelabs.github.io/chrome-for-testing/latest-patch-versions-per-build-with-downloads.json'
$release = $metadata.builds.$build
if (!$release) { throw "No official ChromeDriver build found for Chrome $build." }
$driverUrl = ($release.downloads.chromedriver | Where-Object platform -eq 'win64' | Select-Object -First 1).url
$runtime = Join-Path (Get-Location) '.runtime'
$download = Join-Path $runtime 'chromedriver-win64.zip'
$extracted = Join-Path $runtime 'chromedriver-win64'
Invoke-WebRequest -Uri $driverUrl -OutFile $download
Expand-Archive -LiteralPath $download -DestinationPath $runtime -Force
$driver = Join-Path (Get-Location) 'backend/vendor/laravel/dusk/bin/chromedriver-win.exe'
Copy-Item -LiteralPath (Join-Path $extracted 'chromedriver.exe') -Destination $driver -Force
& $driver --version
```

Gunakan dua terminal PowerShell. Server membaca `.env.testing` secara eksplisit dan hanya bind ke loopback:

```powershell
$env:APP_ENV = 'testing'
./ops/dev/php.ps1 artisan serve --host=127.0.0.1 --port=8031
```

Di terminal lain, jalankan Dusk dengan `APP_ENV` tidak disetel agar Dusk memuat `.env.dusk.local` untuk proses browser:

```powershell
$env:APP_ENV = $null
./ops/dev/php.ps1 artisan dusk --filter=AdminShellTest
# Jalankan semua test browser A-01/A-05:
./ops/dev/php.ps1 artisan dusk
```

Dusk memakai `DatabaseMigrations` terhadap `civora_test`; guard Artisan proyek tetap memverifikasi identitas database disposable sebelum migrasi destruktif. Jangan arahkan Dusk ke `civora_dev` atau database produksi. Jangan commit file environment Dusk lokal maupun biner ChromeDriver. Tutup server loopback dengan Ctrl+C setelah browser test.

Setup memverifikasi paket PHP, menyalin Composer ke `.runtime/`, dan membuat cluster baru `.runtime/pgsql`. Script menolak mengadopsi data directory tanpa marker Civora. Server hanya mendengarkan `127.0.0.1` pada port tinggi yang kosong; startup tersembunyi. Database `civora_dev` dan `civora_test` terpisah. Tidak ada perubahan pada PATH, service, PHP global, atau cluster PostgreSQL yang sudah ada.

Password acak dan APP_KEY hanya ditulis ke berkas lokal yang diabaikan Git: `.runtime/pg-password`, `backend/.env`, `backend/.env.testing`. Script tidak mencetaknya dan tidak menimpa env yang sudah ada. Jangan commit runtime, env privat, vendor, node_modules, log, atau build. `btree_gist` memerlukan hak CREATE EXTENSION; pada deployment siapkan melalui akun migrasi berwenang.

Wrapper PHP selalu berjalan dari `backend`. Setup manual pada sistem lain: sediakan PHP dengan ekstensi di atas dan cluster PostgreSQL baru milik workspace di `.runtime/pgsql`, dengan btree_gist dan database `civora_test`. Buat `.runtime/civora-cluster.json` berisi `data` (path absolut cluster) dan `port` (port tinggi loopback yang dipilih), jalankan `composer install`, salin `.env.example` dan `.env.testing.example`, isi credential/APP_KEY, lalu jalankan migrasi. Database test sekali pakai dibangun ulang.

## Worker notifikasi dan cleanup

Outbox notifikasi dipindai scheduler setiap menit. Jalankan `php artisan schedule:work` dan `php artisan queue:work --tries=1` pada proses backend; job mengunci event, membuat notifikasi in-app secara idempoten, lalu mengirim push. Kegagalan gateway dijadwalkan ulang dengan jeda 15, 30, 60, dan 120 detik; setelah lima percobaan, event berhenti dicoba sementara notifikasi in-app tetap tersedia. Cleanup terjadwal setiap hari pukul 03:15 UTC: selection kedaluwarsa tanpa record, bukti staged berumur 24 jam yang tidak direferensikan, import berumur tujuh hari, dan ekspor yang kedaluwarsa dihapus sesuai jalur privat yang dihasilkan server.

Untuk FCM HTTP v1, `CIVORA_FCM_SERVICE_ACCOUNT_PATH` menunjuk ke service-account JSON privat dan `CIVORA_FCM_PROJECT_ID` opsional menimpa project ID dalam credential. Credential dibaca worker saja; jangan commit atau log isinya. Test memakai mock otomatis dan tidak membuktikan pengiriman perangkat nyata. Verifikasi integrasi nyata tetap menunggu kredensial dan perangkat pada I-01.

## Skema dan batas fondasi

Domain memakai UUID, NISN/NIP teks, dan timestamp PostgreSQL bertimezone. Timezone server/aplikasi dan sesi database adalah UTC. Timezone tampilan sekolah terpisah: `app.school_timezone`/`SCHOOL_TIMEZONE` default Asia/Jakarta, sama dengan default `school_settings.timezone`. Grammar PostgreSQL mengikat DateTime dengan mikrodetik dan offset (`Y-m-d H:i:s.uP`), termasuk timestamp otomatis model domain dan Sanctum. Instant `09:00+07:00` disimpan sebagai `02:00 UTC` dan diserialisasi sebagai JSON UTC. Kolom date-only akademik mempertahankan tanggal kalender; konversi tampilan dilakukan memakai timezone sekolah. Foreign key riwayat memakai RESTRICT. Seluruh entitas dokumen data, relasi Eloquent, constraint unik/check, partial unique semester aktif/review pending, exclusion rentang enrollment `[valid_from, valid_until)`, dan indeks riwayat tersedia.

`PointRecord::revisions()` menyimpan semua revisi; `latestRevision()` memilih revision_number terbesar melalui subquery berkorelasi. `ofMany()` bawaan mengagregasi UUID yang tidak didukung PostgreSQL. Bukti lama tetap direferensikan revisi lama.

## Akun dan bootstrap admin

Jalankan migrasi sebelum membuat admin pertama melalui terminal lokal yang berwenang:

```powershell
./ops/dev/php.ps1 artisan migrate
./ops/dev/php.ps1 artisan civora:create-admin <username> --name="<nama admin>"
```

Command meminta password dan konfirmasi melalui prompt tersembunyi. Tidak ada argumen password, credential yang dicetak, atau seeder admin. Password pribadi minimal 12 dan maksimal 1.024 karakter; default Argon2id Laravel menjaga seluruh frasa sandi, termasuk bagian sesudah 72 byte. Cost production memakai memory 65.536 KiB, time 4, threads 1; PHPUnit saja memakai cost rendah. Verifikasi algoritma hash tetap aktif.

Login siswa memakai body `nisn/password`; guru memakai `identifier/password` untuk NIP atau kode guru. Identifier tetap teks; kode guru/username dinormalisasi trim + huruf kecil, NISN/NIP mempertahankan nol awal. `GET /api/v1/me` dan alias `/api/v1/auth/me` mengembalikan profil/identitas aktivasi. Password sementara 24 jam; Sanctum 7 hari. Ganti password mencabut seluruh sesi sehingga klien perlu login kembali. Login gagal memakai pesan yang sama untuk akun tidak ada, password salah, nonaktif, atau credential sementara kedaluwarsa; ada batas per IP dan identitas login. Route bantuan guru meminta `current_password` dan memberi response `no-store`.

`CreateAccount`, `ActivateAccount`, `ResetAccountPassword`, `DeactivateAccount`, dan `RevealTemporaryCredential` dipakai bersama oleh API dan admin UI. Action admin memeriksa admin aktif dari database; alasan reset/deactivate 5–1.000 karakter dan proses verifikasi identitas sekolah wajib pada caller. Admin UI A-01/A-02 harus menerapkan konfirmasi password terkini sebelum tindakan administratif. `ActivateAccount` adalah action terpercaya: caller wajib mengonfirmasi password lama/sementara sebelum memanggilnya. API menjalankan konfirmasi dan mutasi di transaksi yang sama. Bantuan sementara guru oleh admin akan ditambahkan A-02 dengan aturan autentikasi ulang, TTL, dan audit yang sama; action reveal B-02 menerima `Student`.

Perubahan admin dikunci melalui advisory lock transaksi PostgreSQL sebelum row lock pengguna. Reset admin aktif terakhir ditolak karena mengubahnya menjadi pending_activation; reset admin lain dapat diaktifkan kembali melalui action aktivasi. Penonaktifan admin aktif terakhir juga ditolak. Audit hanya menyimpan identitas resource, role, status, dan alasan; tidak berisi password, hash, secret, atau token. Reset/deactivate/change-password menghapus sesi web/mobile, menonaktifkan semua push binding, mencabut QR, dan mengakhiri selection pending.

`device_tokens.personal_access_token_id` adalah FK bigint nullable ke token Sanctum, SET NULL saat sesi dihapus. Logout menonaktifkan hanya binding milik token saat ini sebelum menghapus token, sehingga sesi lain tetap aktif. B-11 harus mengisi FK tersebut dari token server yang terautentikasi; client tidak memilih ID sesi. Binding lama tanpa FK ikut dinonaktifkan saat reset/deactivate/change-password.

## Siklus akademik B-03

`EnrollStudent::execute(admin, student, semester, classroomId, effectiveAt)` menyiapkan penempatan awal pada semester draft/aktif, dengan kelas/jurusan aktif dan tahun ajaran sesuai. Rentang baru berakhir eksklusif pada tengah malam sekolah sesudah `end_date`. `OpenSemester` memastikan setiap siswa berstatus pendidikan aktif sudah memiliki penempatan yang berlaku, termasuk akun pending_activation, lalu menutup semester lama dan membuka draft baru bersama audit dalam satu transaksi. Semester tertutup tidak dibuka ulang; rentang semester sekolah tidak boleh tumpang tindih dan harus berada dalam tahun ajarannya.

`MoveStudent` memakai semester aktif saat pemanggilan dan mengakhiri penempatan lama tepat pada waktu perpindahan sebelum membuat penempatan baru. Batas akhir penempatan lama dipertahankan untuk penempatan baru; penempatan legacy tanpa batas diakhiri pada batas semester. Perpindahan yang mendahului atau bertepatan dengan record pada penempatan lama ditolak agar rentang historis tetap benar. Record tetap menunjuk enrollment awal. `CurrentEnrollment` adalah lookup temporal `[valid_from, valid_until)` dalam batas kalender semester; caller wajib memeriksa role/status pendidikan/akun dan status semester sebelum operasi bisnis.

`ChangeStudentStatus` hanya menerima transferred/graduated dan alasan 5–1.000 karakter. Action mengakhiri penempatan yang sudah dimulai sebelum waktu server, menonaktifkan akun, mencabut sesi/token/QR/selection/device binding, dan menulis audit secara atomik. Penempatan yang disiapkan dengan `valid_from >= waktu keberangkatan` dipertahankan untuk menghindari rentang nol/negatif; status pendidikan/akun nonaktif mencegah penggunaannya dan siswa tersebut tidak masuk pemeriksaan kesiapan pembukaan semester.

Semua action memeriksa admin aktif dari row terkunci; akun admin inactive/pending/must_change_password ditolak. Urutan lock adalah semester, siswa, lalu user/penempatan/resource turunannya; multi-semester dan multi-siswa dikunci urut ID. Pembukaan dan keberangkatan mengunci semua semester yang ada; penempatan/perpindahan mengunci semester target. Transaksi memakai retry terbatas tiga kali. School calendar memakai `school_settings.timezone` dengan fallback `app.school_timezone`; date-only effectiveAt adalah tengah malam lokal sekolah, timestamp ber-offset mempertahankan instant. Perlombaan perpindahan dengan pencatatan diuji pada B-07 dan pembacaan riwayat guru setelah kelulusan pada B-08.

Sanctum memakai token ID bigint bawaan dengan `tokenable_id` UUID. `qr_tokens.session_id` nullable bigint FK dengan ON DELETE SET NULL: pencabutan sesi mempertahankan metadata QR. B-06 wajib menolak QR bila issuing token hilang/dicabut. Session/cache/queue/failed jobs memakai tabel Laravel. TTL Sanctum 10.080 menit (7 hari), session idle 30 menit; alur autentikasi dan pemeriksaan akun aktif adalah B-02.

Aturan lintas tabel dijalankan di dalam transaksi pada B-02/B-03/B-07: kecocokan role/profil/alias, rentang enrollment di semester dan tahun kelas, kecocokan selection/record, konsumsi QR, serta append-only revisi. B-01 tidak menyediakan action mutasi tersebut. Status import dipilih `preview/confirmed/failed/cancelled`; export mengikuti rancangan admin `queued/processing/completed/failed`. Wadah JSON audit/outbox belum menyediakan filter aman; action/worker berikutnya harus menerapkannya. Disk local Laravel privat. Tidak ada seeder data sekolah/admin contoh.

Perintah destruktif `migrate:fresh`, `migrate:rollback`, `migrate:refresh`, `migrate:reset`, dan `db:wipe` hanya diizinkan pada database disposable milik workspace. Guard Artisan memeriksa koneksi efektif sesudah Laravel menerapkan config cache, URL database, dan opsi `--database`; driver harus PostgreSQL, database `civora_test`, host `127.0.0.1`, port sesuai marker workspace, dan schema `public`. Koneksi read/write terpisah, endpoint direct/pooled, dan selector `::read/::write/::direct` ditolak. Config direct/pooled diparse dari config cache/URL dan ditolak sebelum resolusi PDO, karena Laravel dapat otomatis memilih endpoint direct untuk db:wipe tanpa event command kedua. Setelah pemeriksaan konfigurasi, query identitas read-only pada write PDO memastikan database dan data directory server cocok dengan cluster workspace. Kegagalan marker/koneksi/identitas menolak perintah sebelum handler menghapus skema. Guard berlaku juga saat menggunakan Artisan langsung atau PHPUnit; `--env=testing` dan `--force` tidak melewatinya. Migrasi naik biasa untuk development tetap tersedia.

Rollback hanya pada database test kosong:

```powershell
./ops/dev/php.ps1 artisan migrate:fresh --env=testing --force
./ops/dev/php.ps1 artisan migrate:rollback --env=testing --force
./ops/dev/php.ps1 artisan migrate --env=testing --force
./ops/dev/php.ps1 artisan migrate --env=testing --force
```

Rollback constraint menghapus hanya nama constraint/index migrasi tersebut. Extension btree_gist sengaja dipertahankan agar tidak menghapus extension yang dipakai objek lain.
