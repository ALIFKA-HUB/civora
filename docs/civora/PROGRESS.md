# Kemajuan implementasi Civora

Mulai: 12 Oktober 2026. Cara kerja: subagent implementer dan reviewer terpisah per tugas.

## Status awal

- Rancangan dan 26 tugas tersedia serta telah disetujui.
- Kriteria goal dan verifikasi ditetapkan di [GOALS.md](GOALS.md).
- PHP 8.4.0, Composer 2.8.11, Node 26.3.0, Git 2.47.0, Flutter 3.41.9, dan Dart 3.11.5 ditemukan melalui perintah versi.
- Instalasi PostgreSQL 17/18 ditemukan; tidak ada listener pada port 5432 yang ditemukan pada pemeriksaan awal.
- PHP CLI saat ini tidak memuat pdo_pgsql. Runtime PostgreSQL dan driver PHP harus disiapkan sebelum test database dinyatakan valid.
- B-01 Lulus setelah implementasi, dua putaran perbaikan, dan review independen. 1 dari 26 tugas Lulus; backend keseluruhan belum selesai.

## Milestone

| Goal | Status | Bukti |
| --- | --- | --- |
| G0: Fondasi | Lulus | Commit 3bd1c1a; 84 test/260 assertion, Pint, migration, health HTTP, review bersih |
| G1: Backend | Menunggu review: B-02 login/akun/role | Commit 8faf8db; laporan 113 test/409 assertion lulus, keputusan review belum tersedia |
| G2: Admin | Belum mulai | Belum ada |
| G3: Mobile | Belum mulai | Belum ada |
| G4: Integrasi | Belum mulai | Belum ada |
| G5: Produksi | Belum mulai | Belum ada |

## Log

### 12 Oktober 2026

Pengguna mengizinkan implementasi dan memilih subagent dengan review per tugas. Kriteria kelulusan dipisahkan dari rencana tugas agar keberhasilan harus dibuktikan dengan test, review, dan pemeriksaan platform yang sesuai. Pengguna memilih folder Civora yang sekarang pada branch khusus, tanpa worktree tambahan. Persiapan diarahkan pada database lokal khusus Civora; instalasi/cluster PostgreSQL yang sudah ada tidak diubah.

#### B-01: implementasi selesai, menunggu review independen

Implementer menyerahkan commit 470cf38 untuk fondasi Laravel, 30 model/tabel domain, constraint PostgreSQL, health API, dan script runtime lokal. Runtime proyek: PHP 8.5.11, Composer 2.10.3, PostgreSQL 18.4 pada loopback port 54329; Laravel 13.35.0. Runtime mesin yang ditemukan pada status awal tidak ditimpa.

Laporan uji: 61 test/190 assertion lulus, Pint/Composer/build lulus, HTTP health 200, migration fresh/rollback/re-run pada civora_test lulus. Review menggunakan rentang b80a088..42c7b43, termasuk klarifikasi ID auth native. Hasil ini belum mencentang G0/R1 karena reviewer terpisah belum menyetujui.

Keputusan turunan: ID domain tetap UUID; tabel auth/session/queue bawaan memakai ID native. FK sesi QR nullable SET NULL menjaga metadata ketika token login dicabut; validasi redeem terhadap sesi dicabut menjadi kewajiban B-06. Ini tidak mengubah NISN/NIP sebagai ID login sekolah.

Reviewer mengonfirmasi penulisan timestamp melalui Eloquent dapat kehilangan offset +07 dan bergeser tujuh jam ketika ditafsirkan sesi database UTC. Dua temuan tambahan: jalur reset migration CLI belum memeriksa koneksi disposable yang efektif, dan satu test Unit bawaan hanya assertTrue(true). Ketiga temuan dikembalikan ke implementer; milestone belum dicentang meskipun suite sebelumnya hijau.

Commit perbaikan pertama 5b83c94 melaporkan 78 test/242 assertion lulus. Re-review menyetujui penanganan timestamp dan penghapusan test tanpa perilaku, tetapi menemukan bahwa koneksi direct Laravel dapat berbeda dari PDO utama yang diperiksa guard. Putaran kedua hanya menangani guard tersebut, dengan regresi aman tanpa menjalankan reset pada database non-test.

Commit 3bd1c1a menutup celah koneksi direct/pooled. MigrationSafetyTest: 20 test/59 assertion lulus; full suite: 84 test/260 assertion, exit 0; Pint lulus. Re-review kedua menyatakan seluruh temuan terselesaikan dan tidak ada breakage baru. B-01/G0 Lulus. Langkah berikutnya B-02: login, aktivasi, reset/penonaktifan akun, bantuan password sementara, dan policy role.

Timezone server kini UTC; tampilan/tanggal kalender sekolah tetap memakai timezone sekolah dengan default Asia/Jakarta. Penulisan DateTime memakai format PostgreSQL ber-offset, dengan regresi model domain, token native, dan tanggal kalender.

#### B-02: implementasi selesai, menunggu review independen

Commit 8faf8db menyediakan login terpisah siswa/guru, aktivasi, reset/penonaktifan, bantuan password sementara, policy akses, dan bootstrap admin interaktif. Laporan implementer: 113 test/409 assertion lulus pada PostgreSQL, Pint dan diff check lulus. Review independen belum memutuskan kelulusan.

Logout mencabut perangkat yang terikat sesi saat ini; reset/penonaktifan mencabut seluruh perangkat. Reset admin aktif terakhir ditolak agar tetap ada administrator yang dapat mengelola sekolah. Hashing memakai Argon2id setelah test menemukan pemotongan frasa sandi panjang oleh bcrypt. Konfirmasi password terbaru pada website admin dan pembukaan password sementara guru khusus admin tetap menjadi dependensi A-01/A-02; registrasi perangkat dengan ikatan sesi server menjadi dependensi B-11.
