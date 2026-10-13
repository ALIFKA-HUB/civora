# Civora Admin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Memberikan website admin yang dapat mengelola data sekolah, pemeriksaan, ambang, dan laporan tanpa menggandakan aturan API.

**Architecture:** Blade + Livewire dalam Laravel yang sama. Halaman memanggil action/query rencana backend dan memakai session web, CSRF, policy, serta audit. Tidak ada SPA atau backend admin kedua.

**Tech Stack:** Laravel, Blade, Livewire, Tailwind, PostgreSQL, PHPUnit/Livewire test; browser end-to-end untuk alur utama.

**Spec:** [Indeks](../README.md), [role](../02-role-dan-akun.md), [UI](../07-desain-ui.md), [verifikasi](../08-verifikasi-dan-operasi.md).

## Global Constraints

Seluruh [global constraints](../TASKS.md#global-constraints) berlaku. Admin session 30 menit idle. Password/token tidak muncul pada daftar siswa atau laporan. Semua mutasi memanggil action backend; direct update model dari komponen UI dilarang untuk catatan/review/semester/reset.

## Review Focus

File import berubah referensinya setelah preview (A-02), password temporary sudah expired (A-02), keputusan review basi (A-04), total kelas historis pada ekspor (A-05), dan akses route admin oleh guru (A-01/A-05).

### A-01: Session admin, layout, tema, dan dashboard

**Depends:** B-01/B-02/B-05.

**Files:** Create backend/app/Livewire/Admin/{Login,Dashboard}.php; backend/resources/views/layouts/admin.blade.php; backend/resources/views/livewire/admin/{login,dashboard}.blade.php; backend/resources/css/civora-theme.css; backend/tests/Feature/AdminSessionTest.php; Modify backend/routes/web.php dan backend/resources/css/app.css.

**Interfaces:** Login::authenticate(): void memanggil authenticator web dengan role admin; Dashboard memakai query pending review dan current threshold tanpa mengubah data. Route /admin/login, /admin, serta named group admin.* hanya menerima admin active.

Admin pending setelah reset memiliki jalur aktivasi terbatas untuk identitas, ganti password, dan logout. Konfirmasi password terbaru untuk mutasi akun administratif diterapkan pada caller web; action B-02 tetap memakai interface yang disetujui.

- [x] Tulis test guru/siswa ditolak dari /admin, session idle 30 menit habis, CSRF gagal ditolak, dan admin last-account protection dari B-02 tetap berlaku.
- [x] Pin akses `self::assertFalse(Gate::forUser($teacher)->allows('access-admin'));`; login admin valid diarahkan ke /admin tanpa menampilkan password.
- [x] Jalankan `php artisan test --filter=AdminSessionTest` dan lihat gagal.
- [x] Implementasikan layout sidebar kelompok pada dokumen 07, header sekolah/semester, terang/gelap, font self-host, fokus keyboard, dan dashboard antrean. Verifikasi dependency Livewire/Tailwind sebelum import.
- [x] Jalankan test lulus dan QA keyboard/layout pada desktop serta layar sempit; commit shell admin.

### A-02: Siswa, guru, akademik, import, dan bantuan akun

**Depends:** A-01/B-03/B-09.

**Files:** Create backend/app/Livewire/Admin/{Students,StudentDetail,Teachers,Academic,AccountsImport}.php; backend/resources/views/livewire/admin/{students,student-detail,teachers,academic,accounts-import}.blade.php; backend/tests/Feature/AdminDataManagementTest.php; Modify backend/routes/web.php.

**Interfaces:** Students/Teachers memanggil CreateAccount/ResetAccountPassword/DeactivateAccount B-02; Academic memanggil OpenSemester/EnrollStudent/MoveStudent/ChangeStudentStatus B-03. EnrollStudent menyiapkan penempatan awal sebelum semester dibuka. AccountsImport::preview(): void dan AccountsImport::confirm(): void memakai PreviewAccountsImport/CommitAccountsImport B-09. Komponen memakai batch ID milik admin, bukan membaca baris yang dikirim browser sebagai sumber kebenaran.

B-02 menyediakan RevealTemporaryCredential dengan target Student. A-02 menambahkan pembukaan credential sementara guru khusus admin, dengan konfirmasi password terbaru, TTL 24 jam, audit tanpa secret, dan penolakan setelah aktivasi/kedaluwarsa. Jangan memperluas hak guru ke credential guru lain.

- [ ] Tulis Livewire test tambah siswa/guru tanpa NIP, class movement, lulus, temporary expired, import errors per baris, dan konfirmasi batch admin lain.
- [ ] Pin import invalid dengan `->assertHasErrors()` dan jumlah User tidak berubah; confirm batch valid menghasilkan NISN nol awal persis. Uji kode kelas yang dinonaktifkan setelah preview memblokir commit.
- [ ] Jalankan `php artisan test --filter=AdminDataManagementTest`; implementasikan tabel/filter/pagination, detail historis, form, reset beralasan/reauth, template XLSX/CSV, preview, konfirmasi, dan hasil batch.
- [ ] Verifikasi hasil import tidak berisi password pada error/export umum; perubahan kelas tidak mengubah catatan lama. Sediakan pencarian audit distribusi credential.
- [ ] Jalankan test dan alur browser dari template sampai akun aktif; commit pengelolaan data.

### A-03: Katalog poin, ambang, dan tindak lanjut

**Depends:** A-01/B-04/B-05.

**Files:** Create backend/app/Livewire/Admin/{PointTypes,Thresholds,FollowUps}.php; backend/resources/views/livewire/admin/{point-types,thresholds,follow-ups}.blade.php; backend/tests/Feature/AdminPointRulesTest.php; Modify backend/routes/web.php.

**Interfaces:** PointTypes memakai action B-04; Thresholds memakai SetThresholdPolicy B-05; FollowUps memakai RecordFollowUp B-05. Versi kebijakan dan versi katalog ditampilkan pada detail administratif.

- [ ] Tulis test nilai nonpositif ditolak, revisi katalog menjaga nilai lama, tingkat ambang duplikat ditolak, dan status follow-up tidak mengurangi total.
- [ ] Pin tipe dinonaktifkan tidak hilang dari riwayat; mengganti threshold menghasilkan policy version +1, bukan menimpa policy lama.
- [ ] Jalankan `php artisan test --filter=AdminPointRulesTest`; implementasikan form, konfirmasi revisi, status aktif, versi, dan daftar peringatan berdasarkan kebijakan terbaru.
- [ ] Jalankan lulus dan periksa label semantik pada terang/gelap; commit aturan admin.

### A-04: Catatan, antrean pemeriksaan, dan audit perubahan

**Depends:** A-01/B-08.

**Files:** Create backend/app/Livewire/Admin/{PointRecords,ReviewQueue,ReviewDetail}.php; backend/resources/views/livewire/admin/{point-records,review-queue,review-detail}.blade.php; backend/tests/Feature/AdminReviewWorkflowTest.php; Modify backend/routes/web.php.

**Interfaces:** ReviewDetail::decide(): void memanggil DecideReviewRequest dengan expectedRevision yang dibaca saat layar dibuka. Koreksi langsung memakai ReviseRecord dan menyelesaikan pending review dalam transaksi yang sama. Lampiran melalui AttachmentPolicy.

- [ ] Tulis test approve/reject beralasan, cancellation, expectedRevision basi, perubahan katalog sejak review dibuka, dan pemohon siswa/guru tampil dengan identitas yang tepat.
- [ ] Pin keputusan basi `assertHasErrors('revision')` atau konflik terstruktur yang dirender sebagai permintaan membaca ulang; jumlah revision tidak bertambah.
- [ ] Jalankan `php artisan test --filter=AdminReviewWorkflowTest`; implementasikan daftar/filter pending, detail catatan/bukti, alasan pemohon, snapshot sebelum/sesudah, dan pilihan keputusan.
- [ ] Verifikasi pembatalan tidak menawarkan pemindahan siswa, nilai baru dari versi katalog, dan status pending tidak dikira sudah disetujui; jalankan lulus lalu commit.

### A-05: Laporan, pengaturan, dan audit operasional

**Depends:** A-02/A-03/A-04/B-10/B-11.

**Files:** Create backend/app/Livewire/Admin/{Reports,Settings,AuditLogViewer}.php; backend/resources/views/livewire/admin/{reports,settings,audit-log-viewer}.blade.php; backend/tests/Feature/AdminReportAccessTest.php; Create backend/tests/Browser/AdminSchoolWorkflowTest.php; Modify backend/routes/web.php.

**Interfaces:** Reports memakai PointReportQuery/RequestReportExport B-10; status export queued/processing/completed/failed ditampilkan. Settings mengubah singleton school settings dan akun admin dengan audit; AuditLogViewer read-only, tidak menampilkan secret.

- [ ] Tulis test filter kelas/semester historis, status export, download admin lain/guru ditolak, timezone sekolah, settings invalid, dan audit yang disanitasi.
- [ ] Pin laporan kelas lama tetap 10 dan kelas baru 5 pada fixture B-10; export expired tidak dapat diunduh meskipun URL tersimpan.
- [ ] Jalankan `php artisan test --filter=AdminReportAccessTest`; implementasikan rekap/filter, permintaan download privat, pengaturan sekolah/logo opsional, manajemen admin, dan audit berpagination.
- [ ] Buat satu browser test alur import → katalog → membuka semester → menerima catatan API → pemeriksaan → laporan. Jalankan melalui runner browser yang dipilih/didokumentasikan pada B-01, tanpa database produksi.
- [ ] Jalankan semua test admin sampai lulus; periksa keyboard, tabel panjang, focus dialog, dan tema. Commit; kualitas PDF/Excel akhir diperiksa pada I-02.
