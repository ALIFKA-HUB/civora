# Civora Integration and Release Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Membuktikan alur lintas platform, kualitas ekspor, dan kesiapan operasi Civora sebelum dipakai sekolah.

**Architecture:** Laravel/PostgreSQL staging bersama dua aplikasi Flutter dan web admin. Release memakai satu deployment dengan worker/scheduler, storage privat, TLS, dan backup offsite. Bukti disimpan tanpa data/credential siswa nyata.

**Tech Stack:** Stack pada [rencana utama](../TASKS.md), perangkat Android nyata, PostgreSQL backup/restore, dan alat inspeksi XLSX/PDF.

**Spec:** [Skenario penerimaan dan operasi](../08-verifikasi-dan-operasi.md), [API](../06-arsitektur-api-keamanan.md), [UI](../07-desain-ui.md).

## Global Constraints

Seluruh [global constraints](../TASKS.md#global-constraints) berlaku. Jangan menggunakan data sekolah nyata pada QA publik. Tidak ada publikasi, pembelian, atau pengiriman credential sebagai efek samping test. Deploy produksi memerlukan tujuan/akun/infrastruktur yang benar-benar diberikan pada tahap eksekusi.

## Review Focus

Expiry QR tepat saat scan (I-01), queue mati setelah commit (I-01), PDF panjang dan formula Excel (I-02), backup tanpa lampiran/kunci (I-03), dan kapasitas import/laporan dengan ukuran data sekolah (I-04).

### I-01: Alur lintas tiga platform dan push nyata

**Depends:** B-11/A-05/M-06.

**Files:** Create backend/tests/Feature/CivoraEndToEndApiTest.php; docs/qa/end-to-end.md; Modify app integration tests M-06 dan backend/tests/Browser/AdminSchoolWorkflowTest.php.

**Interfaces:** Consumes seluruh endpoint/action yang sudah dibuat. Produces bukti scenario ID ACC/QR/REC/REV/SEM/NOT pada dokumen 08, dengan hasil/pass/fail dan versi build yang diuji.

- [ ] Siapkan sekolah/kelas/semester sintetis, dua guru, siswa dengan NISN nol awal, dan admin. Daftarkan dua Android app FCM hanya ketika akun/config resmi untuk staging tersedia.
- [ ] Tulis test API lintas role: import/aktivasi → QR → scan → record → objection → admin correction → riwayat/summary. Pin prestasi 10, pelanggaran awal 15 dikoreksi 5, total akhir 10/5 terpisah.
- [ ] Jalankan `php artisan test --filter=CivoraEndToEndApiTest` dan integration/browser test yang didokumentasikan. Periksa scan QR antara dua perangkat, bukan gambar mock saja.
- [ ] Matikan worker setelah commit catatan lalu hidupkan; pastikan in-app/outbox/push tidak hilang atau ganda. Cek background/foreground/klik push ketika logout, serta izin push ditolak.
- [ ] Uji akun lulus, akses lampiran silang, QR replay, perubahan kelas/semester saat draft, dan retry respons simpan hilang. Catat hasil serta perbaiki failure pada task pemiliknya sebelum lanjut.
- [ ] Jalankan test terkait sekali lagi setelah perbaikan, lalu commit bukti QA yang disanitasi.

### I-02: Kualitas dan kesamaan Excel/PDF

**Depends:** B-10/A-05/I-01.

**Files:** Create docs/qa/exports.md dan backend/tests/Feature/ExportConsistencyTest.php; Modify backend/resources/views/reports/points.blade.php bila ada cacat layout.

**Interfaces:** Produces bukti XLSX/PDF yang berasal dari filter dan snapshot query yang sama. Generated sample berada di storage privat test/staging atau direktori QA lokal yang tidak dipublikasikan.

- [ ] Tulis fixture 100+ catatan, dua kelas historis, beberapa semester, cancellation/revision, nama/keterangan panjang, dan teks menyerupai formula.
- [ ] Pin jumlah row dan total efektif export sama dengan PointReportQuery untuk filter yang sama; NISN "0012345678" tetap teks; formula tidak dieksekusi.
- [ ] Jalankan `php artisan test --filter=ExportConsistencyTest`. Buka XLSX untuk memeriksa format angka/identifier dan render PDF menjadi gambar halaman dengan alat dokumen yang tersedia saat eksekusi.
- [ ] Periksa header berulang, nomor halaman, kolom/nama panjang, overflow, judul/filter/timezone, serta tidak ada password/token. Perbaiki template dan ulangi hanya pemeriksaan yang terdampak.
- [ ] Simpan hasil QA/pass dalam docs/qa/exports.md; commit setelah kesamaan data dan layout terbukti.

### I-03: Runbook, backup, restore, dan secret

**Depends:** I-01/I-02; lingkungan staging dan lokasi backup tersedia.

**Files:** Create docs/operations/{deployment.md,backup-restore.md,incident-response.md}; Modify backend/.env.example dan docs/development.md; Create script backup/restore di ops/ hanya jika tidak cukup menggunakan perintah PostgreSQL/runbook yang terdokumentasi.

**Interfaces:** Produces deployment yang menjalankan web/API + worker + scheduler dan prosedur pemulihan yang telah dicoba. Tidak menyimpan nilai credential, signing key, atau encryption key di runbook/repository.

- [ ] Dokumentasikan konfigurasi TLS, database, disk privat, FCM secret, session/queue, migration, worker/scheduler, health check, serta rollback release.
- [ ] Buat backup database + lampiran terenkripsi offsite; jalankan restore di lingkungan terisolasi dengan akses key yang sah.
- [ ] Pin setelah restore: jumlah record/revision sama, contoh lampiran dapat dibaca oleh pemilik sah, akses silang ditolak, audit tetap ada, dan worker tidak mengirim ulang event processed.
- [ ] Ukur durasi restore terhadap target awal 4 jam dan umur backup terhadap 24 jam. Catat hasil nyata; jika target belum tercapai, tulis batasan dan perbaikan, jangan menyatakan siap.
- [ ] Verifikasi cleanup tidak menghapus selection yang terikat record; backup gagal/queue tertunda menghasilkan alarm yang terdokumentasi. Commit runbook dan bukti tanpa secret.

### I-04: Kapasitas, penerimaan sekolah, dan keputusan release

**Depends:** I-03; jumlah siswa/guru dan cara distribusi yang nyata tersedia dari sekolah pada tahap deployment.

**Files:** Create docs/qa/release-checklist.md dan docs/operations/capacity.md; Modify docs/civora/TASKS.md untuk status milestone yang benar-benar dibuktikan.

**Interfaces:** Produces checklist go/no-go dengan hasil, pemilik operasi, versi dua app/backend, serta dependency yang masih memerlukan tindakan. Keputusan release terpisah dari kelulusan test.

- [ ] Ukur pencarian, simpan poin, import hingga batas 5.000 baris, serta laporan dengan data sintetis seukuran sekolah. Catat latency, penggunaan resource, dan hasil concurrency; pilih kapasitas server berdasarkan data itu.
- [ ] Jalankan pemeriksaan final yang relevan: seluruh backend test, browser admin, analyze/test/build mobile, skenario perangkat inti, export QA, dan restore yang sudah dibuktikan.
- [ ] Admin sekolah meninjau contoh daftar poin, ambang, template import, semester/kelas, serta prosedur bantuan akun. Nilai aturan sekolah tidak diganti dengan contoh dalam dokumentasi.
- [ ] Verifikasi dependency produksi: domain/TLS, backup, key/signing, FCM, distribusi app, credential admin yang aman, dan penanggung jawab operasi tersedia.
- [ ] Laporkan go/no-go beserta batasan konkret. Publikasi/deploy produksi hanya dilakukan saat diminta dalam konteks eksekusi; jangan menganggap permintaan brainstorming sebagai izin release.
- [ ] Centang milestone hanya jika bukti lengkap dan hasil lulus; commit catatan release tanpa menyatakan kesiapan fitur yang belum diverifikasi.
