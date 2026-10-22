# Civora Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menghasilkan Civora untuk satu sekolah dengan dua aplikasi Android, website admin, dan data poin konsisten per semester.

**Architecture:** Satu Laravel menangani admin dan API. Dua aplikasi Flutter berbagi paket core/UI; PostgreSQL menyimpan data bisnis, queue, dan session. Tidak ada microservice, web app terpisah, atau antrean pencatatan offline.

**Tech Stack:** Flutter/Material 3, Laravel/Sanctum/Blade/Livewire/Tailwind, PostgreSQL, FCM.

**Spec:** [Indeks rancangan](README.md), terutama [aturan poin](04-aturan-poin.md), [model data](05-model-data.md), dan [kontrak API](06-arsitektur-api-keamanan.md).

Tanggal rancangan: 11 Oktober 2026. Implementasi diizinkan pengguna pada 12 Oktober 2026 dengan subagent dan review per tugas. Status aktual merujuk [PROGRESS.md](PROGRESS.md); kriteria kelulusan merujuk [GOALS.md](GOALS.md). Pembuatan layanan eksternal dan release mengikuti konteks eksekusi, bukan otomatis akibat adanya dokumen.

## Global Constraints

- Satu sekolah; tiga role: siswa, guru, admin.
- Dua aplikasi Android terpisah dengan Flutter. Laravel menyatukan website admin dan API. Database PostgreSQL.
- QR berganti setiap 20 detik, sekali pakai, dan hanya memilih siswa. Pencarian nama/NISN tersedia sebagai alternatif.
- Sesi pemilihan siswa berlaku 5 menit dan berbeda dari umur QR 20 detik.
- Versi pertama online. Poin berlaku setelah guru menyimpan; keterangan wajib, foto opsional.
- Prestasi dan pelanggaran terpisah; total dimulai kembali setiap semester, riwayat tetap tersimpan.
- Keterangan/alasan 5-1.000 karakter; satu JPG/PNG maksimal 5 MB.
- Maksimal satu pemeriksaan pending per catatan. Pembatalan tidak menghapus riwayat.
- Password sementara berlaku 24 jam. Sanctum mobile TTL 7 hari; admin session 30 menit idle.
- Import hanya akun baru, satu batch atomik, 5.000 baris/10 MB. NISN/NIP selalu teks.
- Tema putih + teal, mode terang/gelap, bahasa Indonesia, default timezone Asia/Jakarta.
- Semua berkas bukti/ekspor privat. Tidak ada password, QR mentah, atau token dalam log.

## Review Focus

1. Respons scan hilang setelah redeem berhasil: retry request_key sama tidak membuat selection baru; diuji B-06/M-04.
2. Respons simpan hilang setelah commit: retry kunci sama mengembalikan satu catatan, payload berbeda konflik; diuji B-07/M-04.
3. Guru membuka catatan sendiri setelah siswa lulus: riwayat tetap terbaca, pencatatan baru ditolak; diuji B-03/B-08/M-04.
4. Data kelas/katalog berubah setelah preview/formulir dibuka: validasi ulang tanpa menimpa diam-diam; diuji B-07/B-09/A-02/M-04.
5. HP tanpa push/izin kamera, teks membesar, dan mode gelap: fitur inti/manual tetap dapat digunakan dan QR terbaca; diuji M-01/M-03/M-04/I-01.

## Peta rencana dan urutan

Pekerjaan visual yang disetujui setelah rencana awal dikelola terpisah pada [paket redesign](redesign/README.md) dan [rencana RD-01–RD-04](redesign/TASKS.md). Task RD belum dimulai dan tidak menambah/mengubah hitungan 26 task utama pada tabel serta milestone di bawah. Domain/integrasi tetap memakai dependensi dan bukti yang sama.

| Bagian | Acuan tugas | Hasil yang dapat diperiksa |
| --- | --- | --- |
| 1 | [Backend/API](tasks/01-backend.md) | API, domain, migration, dan kontrol akses yang dapat diuji tanpa UI |
| 2 | [Website admin](tasks/02-admin.md) | Admin mengelola data hingga laporan melalui browser |
| 3 | [Aplikasi mobile](tasks/03-mobile.md) | Dua build Android dengan alur siswa/guru lengkap |
| 4 | [Integrasi dan release](tasks/04-integrasi-release.md) | Bukti alur lintas platform, ekspor, dan kesiapan operasi |

Urutan utama: B-01 → B-02/B-03 → B-04/B-05 → B-06 → B-07 → B-08 → B-09/B-10/B-11. Tugas dengan dua ID dipisahkan garis miring dapat dikerjakan berurutan dalam urutan yang paling praktis; ini bukan instruksi otomatis menjalankan agen paralel.

Admin dan mobile baru diintegrasikan setelah kontrak backend yang dikonsumsi tugas tersebut lulus. Fondasi tema mobile dapat dikerjakan setelah B-01, tetapi layar yang belum terhubung tidak boleh ditandai sebagai fitur selesai. Integrasi/release menunggu ketiga produk selesai.

## Aturan pelaksanaan

1. Baca spec dan tugas terkait sebelum menulis kode. Daftar path dalam rencana adalah file yang direncanakan, bukan file yang sudah ada.
2. Siapkan Git/checkout dan runtime pada awal implementasi; jangan mengasumsikan repository sudah diinisialisasi. Catat versi yang benar-benar dipakai pada docs/development.md.
3. Untuk domain/batas akses/kegagalan, tulis test yang membuktikan perilaku, jalankan agar kegagalannya terlihat, implementasikan minimum, lalu jalankan sampai lulus. Tidak perlu test kosmetik yang hanya menyalin struktur UI.
4. Snippet assertion pada tugas menentukan kontrak hasil; setup fixture ditulis pada test masing-masing dengan nilai yang disebutkan. Snippet bukan test yang sudah dijalankan.
5. Commit perubahan fokus per tugas setelah verifikasi lulus; jangan membuat commit saat tahap perencanaan ini. Gunakan pesan yang menyebut hasil tugas.
6. Tugas selesai hanya jika langkahnya dicentang dengan bukti hasil. Memiliki file, mock UI, atau endpoint belum berarti alur selesai.
7. Jika perubahan kontrak dibutuhkan, perbarui spec, signature yang dikonsumsi, dan test terkait sebelum lanjut. Jangan membangun adaptasi diam-diam di setiap klien.
8. Release/akun Firebase/domain/server/signing key dikerjakan setelah kebutuhan akses dan tujuan deployment konkret tersedia. Kredensial tidak dimasukkan ke Markdown.

## Status milestone

- [x] R1: Backend/API dan kontrol domain lulus.
- [x] R2: Website admin terhubung ke action backend dan lulus alur browser.
- [ ] R3: Dua aplikasi Android terhubung dan lulus alur perangkat.
- [ ] R4: Integrasi, laporan, backup/restore, dan operasi diverifikasi.

Centang milestone hanya setelah memenuhi [GOALS.md](GOALS.md) dengan bukti yang tercatat. Persiapan lingkungan tidak mencentang R1.
