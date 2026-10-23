# Runbook deployment Civora

Status: prosedur persiapan. Belum ada tujuan staging/production, domain, credential, atau server yang diberikan. Menjalankan checklist ini pada infrastruktur nyata memerlukan pemilik dan target deployment; dokumen ini tidak menyatakan deployment sudah dilakukan.

## Bentuk layanan

Satu release Laravel melayani web admin dan API pada satu domain HTTPS. Release membutuhkan PostgreSQL, disk lokal privat yang persisten, satu worker queue, dan satu scheduler. Queue, cache, dan session memakai tabel PostgreSQL secara default; jangan arahkan dua lingkungan ke database atau disk yang sama. Disk `local` Laravel menunjuk ke `backend/storage/app/private`, menyimpan lampiran, import, dan ekspor sebagai file biasa. Akses aplikasi tetap melalui policy dan controller; jangan buat symlink `storage:link` untuk direktori privat. Terapkan enkripsi volume di host. File backup wajib dienkripsi sebelum diunggah ke penyimpanan offsite.

Rancangan awal untuk 3.000 siswa dan 200 guru adalah ukuran data, bukan jaminan kapasitas atau jumlah pengguna serentak. Ukuran host baru diputuskan setelah pengukuran I-04.

## Prasyarat dan rahasia

- PHP 8.3–8.5 dengan ekstensi proyek (termasuk `pdo_pgsql`, `pgsql`, `openssl`, `fileinfo`, `mbstring`, `zip`, `intl`, dan `gd`), Composer, Node untuk build aset, dan PostgreSQL dengan dukungan ekstensi `btree_gist`.
- Role migrasi PostgreSQL yang berwenang membuat ekstensi `btree_gist`, menjalankan migrasi, dan membuat tabel. Role runtime memakai hak minimum yang diperlukan aplikasi. Simpan koneksi melalui secret manager atau file env berizin ketat di luar release.
- `APP_KEY` unik untuk lingkungan, dipertahankan saat deploy dan dipulihkan dari custody rahasia saat disaster recovery. Key ini diperlukan untuk membuka `temporary_credentials.encrypted_secret`, selain cookie/session terenkripsi. Jangan jalankan `key:generate` pada deploy biasa atau restore.
- Simpan kredensial PostgreSQL, `APP_KEY`, file service account FCM, kunci penerima/enkripsi backup, serta signing key Android di lokasi custody terpisah dan batasi akses per peran. Jangan masukkan nilai ke `.env.example`, image build, log, tiket, atau repo. Service account FCM hanya dibaca worker.
- Siapkan domain dan sertifikat TLS tepercaya. Redirect HTTP ke HTTPS di terminator TLS, cegah akses langsung publik ke port backend, dan verifikasi konfigurasi forwarded-proto/proxy sebelum menjalankan Laravel di belakang reverse proxy. Seluruh akses web/API dan koneksi operator harus memakai kanal terenkripsi.
- Untuk PostgreSQL jarak jauh, set `DB_SSLMODE=verify-full` dan pasang CA tepercaya sesuai provider (misalnya melalui `PGSSLROOTCERT`). Jangan turunkan verifikasi sertifikat untuk memperbaiki masalah koneksi.
- Sediakan filesystem privat persisten, permission pemilik service untuk baca/tulis, kapasitas dan alert ruang kosong. Jangan menyajikan direktori itu sebagai web root.
- Staging harus memakai database, disk, `APP_KEY`, dan service account terpisah serta hanya data sintetis. Tidak ada Firebase project/credential saat dokumen ini dibuat; push tetap nonaktif sampai pemilik menyediakan konfigurasi nyata.

Pada staging/production, gunakan sekurangnya konfigurasi berikut (nilai rahasia hanya dari secret store): `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domain>`, `DB_CONNECTION=pgsql`, `DB_SSLMODE=verify-full`, `SESSION_DRIVER=database`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`, `FILESYSTEM_DISK=local`, `QUEUE_CONNECTION=database`, dan `CACHE_STORE=database`. Batasi `SESSION_DOMAIN` ke host yang memang diperlukan; kosong/null mempertahankan cookie host-only. Atur `LOG_LEVEL` sesuai kebijakan dan pastikan tujuan log dipantau serta tidak merekam credential, token, QR mentah, atau konten bukti.

## Prosedur release

Gunakan release directory/version yang dapat dipilih kembali dengan `.env` dan disk privat persisten yang dikelola terpisah. Sebelum migrasi, ambil backup database dan direktori privat sesuai [runbook backup dan restore](backup-restore.md), catat release serta revisi migrasi saat ini, dan pastikan rollback skema kompatibel.

1. Bangun release dari commit yang ditinjau. Di `backend/`, gunakan `composer install --no-dev --prefer-dist --optimize-autoloader` dan `npm ci --ignore-scripts`, lalu `npm run build`. Jangan menyalin `.env` lokal, `vendor`, atau `node_modules` dari workstation.
2. Pasang file konfigurasi dari secret store dan cek konfigurasi tanpa mencetak nilai: `php artisan about`, `php artisan migrate:status`, serta pemeriksaan koneksi yang disetujui. Pastikan `APP_DEBUG=false`, URL HTTPS, `DB_SSLMODE=verify-full`, direktori FCM di luar release bila digunakan, dan disk privat menunjuk ke volume yang benar.
3. Jadwalkan maintenance window. Hentikan scheduler dan worker lama, aktifkan maintenance/isolasi trafik web, lalu jalankan migrasi naik biasa: `php artisan migrate --force`. Migrasi `btree_gist` membutuhkan izin `CREATE EXTENSION` saat pertama kali. Jangan menjalankan `migrate:fresh`, `db:wipe`, atau seeder pada lingkungan sekolah.
4. Setelah migrasi berhasil, jalankan `php artisan config:cache` dan `php artisan view:cache`. Jangan cache konfigurasi sebelum secret environment terpasang. Restart seluruh proses PHP/web, worker, dan scheduler supaya semuanya memakai kode dan config release yang sama.
5. Lepas maintenance dan lakukan smoke check: `curl --fail --silent --show-error https://<domain>/up`, `curl --fail --silent --show-error https://<domain>/api/v1/health`, `pg_isready` pada endpoint database yang benar, dan `psql "$DATABASE_URL" -X -v ON_ERROR_STOP=1 -c 'select 1'`. Health endpoint HTTP mengembalikan status aplikasi saja; ia tidak membuktikan DB/queue siap. Periksa satu login admin uji staging, satu permintaan baca API tanpa data sensitif, serta metrik queue dan disk. Jangan menguji push nyata tanpa akun Firebase/perangkat staging yang disetujui.
6. Jalankan worker persisten `php artisan queue:work database --sleep=3 --timeout=80 --tries=2` dan scheduler persisten `php artisan schedule:work` di bawah process manager dengan restart saat gagal, log terpantau, dan stop/start saat deploy. Queue database `retry_after` default 90 detik; timeout job ekspor dibatasi maksimum 60 detik. Jangan menjalankan scheduler pada lebih dari satu host kecuali koordinasi lock/cache bersama sudah dikonfigurasi dan diuji.

Pastikan jadwal `dispatch-civora-notifications` berjalan setiap menit dan `civora:cleanup-temporary-resources` berjalan harian pukul 03:15 UTC. Cleanup menghapus selection kedaluwarsa hanya bila tak terikat record, lampiran staged yang tidak direferensikan, import lama, dan ekspor kedaluwarsa; ia mempertahankan lampiran yang direferensikan revisi serta riwayat/audit. Periksa sisa antrean dan error setelah release.

## Health, kesiapan, dan alarm

Laravel menyediakan `/up`; Civora juga memiliki `GET /api/v1/health` yang mengembalikan `{"status":"ok"}`. Keduanya tidak memeriksa database, disk, FCM, atau antrean. Readiness operasi harus menggabungkan status HTTP tersebut dengan `pg_isready`, query `select 1`, ruang disk, status proses worker/scheduler, umur antrean, dan umur backup. Jangan menambahkan kredensial atau data siswa ke respons health.

Implementasi repo belum mengirim alarm ke layanan monitoring. Sebelum penggunaan nyata, pemilik operasi harus menghubungkan metrik/log ke kanal alarm yang benar dan melakukan uji penerimaan pengiriman alarm. Minimal alarm: backup tidak sukses atau lebih tua dari 24 jam; ruang disk di bawah ambang yang dipilih operator; worker/scheduler berhenti; job gagal; antrean/outbox tertua melewati ambang operasional yang disetujui; serta event outbox dengan `attempts >= 5`. Catat pemilik penerima dan jalur eskalasi tanpa mencatat secret.

## Rollback aplikasi

Rollback kode hanya aman bila release sebelumnya kompatibel dengan skema database saat ini. Gunakan perubahan skema expand/contract: tambah struktur yang kompatibel sebelum kode baru memakainya, pertahankan kompatibilitas selama masa rollback, dan hapus struktur lama pada release terpisah setelah verifikasi. Periksa setiap `down()` migration sebelum menggunakannya.

Jika smoke check gagal, aktifkan maintenance, hentikan worker/scheduler, simpan log/request ID yang sudah disanitasi, dan pulihkan pointer aplikasi ke release sebelumnya hanya setelah memastikan skema yang sudah diterapkan masih kompatibel. Restart proses lama lalu jalankan smoke check lagi. Jangan otomatis menjalankan `migrate:rollback`; ia dapat menghapus data atau objek yang dibutuhkan release sebelumnya. Bila schema/data tidak kompatibel, pertahankan layanan terisolasi dan ikuti restore pasangan database+disk ke target terpisah serta alur pemulihan yang disetujui. Tidak ada prosedur rollback produksi yang terbukti sebelum latihan staging.

## Gerbang status

Checklist ini belum berarti siap produksi. Staging, domain/TLS, secret custody, FCM, offsite backup, kapasitas, alarm, dan pemilik operasi harus disediakan; restore drill serta pemeriksaan perangkat I-01 tetap harus dicatat dengan hasil nyata. Lihat [kemajuan proyek](../civora/PROGRESS.md).
