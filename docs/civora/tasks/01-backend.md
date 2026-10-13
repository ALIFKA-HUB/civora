# Civora Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menyediakan backend/API Civora dengan akses terpisah, poin konsisten, dan operasi yang dapat diuji.

**Architecture:** Laravel modular dengan action domain bersama website/API. PostgreSQL memberikan constraint dan transaksi; outbox serta queue database menangani notifikasi. Dua mobile client tidak menentukan nilai poin atau identitas pencatat.

**Tech Stack:** Laravel, Sanctum, PostgreSQL, PHPUnit, database queue, FCM HTTP v1.

**Spec:** [Indeks](../README.md), [role](../02-role-dan-akun.md), [alur](../03-alur-pengguna.md), [aturan](../04-aturan-poin.md), [data](../05-model-data.md), [API](../06-arsitektur-api-keamanan.md).

## Global Constraints

Seluruh [global constraints pada TASKS.md](../TASKS.md#global-constraints) berlaku. Request path mengikuti /api/v1; akses/aktif/aktivasi diperiksa server. Test transaksi memakai PostgreSQL, bukan SQLite saja. Nama model mengikuti entitas dalam dokumen data; semua path di bawah relatif terhadap root Civora.

## Review Focus

Retry scan/simpan setelah respons hilang (B-06/B-07), namespace NIP/kode guru bertabrakan (B-02), perpindahan kelas paralel (B-03/B-07), koreksi paralel (B-08), dan notifikasi ulang (B-11) harus diuji dengan hasil konkret.

## Peta file

backend/app/Models berisi model Eloquent. backend/app/Actions/{Accounts,Academic,Catalog,Points,Reviews,Imports,Reports} berisi mutasi domain; backend/app/Services berisi query total/ambang/notifikasi. backend/app/Http/{Controllers/Api,Requests,Resources} mengubah HTTP ke action dan resource. backend/app/Policies melindungi resource. Queue job tidak menggandakan aturan domain.

### B-01: Fondasi Laravel, PostgreSQL, dan model awal

**Depends:** Rancangan disetujui; izin implementasi kelak tersedia.

**Files:** Create backend/{composer.json,package.json,.env.example,phpunit.xml,routes/api.php}, backend/database/migrations/* untuk seluruh entitas dokumen 05, backend/app/Models/{User,Student,Teacher,Semester,Enrollment,PointType,PointTypeVersion,PointRecord,RecordRevision,ReviewRequest,StudentSelection,QrToken,ThresholdPolicy,ThresholdLevel,ThresholdAlert,FollowUp,Attachment,Notification,OutboxEvent,ImportBatch,ReportExport,AuditLog}.php dan model referensi akademik/credential; Test backend/tests/Feature/DatabaseConstraintsTest.php; Create docs/development.md.

**Interfaces:** Produces model/relasi dokumen 05, endpoint GET /api/v1/health dengan status saja, koneksi test PostgreSQL terisolasi. PointRecord::revisions() menghasilkan semua revisi; PointRecord::latestRevision() menghasilkan revisi terbesar. Tidak ada seeder data sekolah nyata.

- [x] Verifikasi runtime PHP/Composer/Node/PostgreSQL, buat proyek Laravel pada backend, dan dokumentasikan versi serta perintah setup. Siapkan Git hanya ketika eksekusi dimulai.
- [x] Buat fixture sintetis dan test constraint: NISN identik ditolak, satu semester active, satu pending review, selection_id catatan unik, points positif. Assertion untuk duplicate adalah exception database yang sesuai, bukan keberhasilan validasi UI saja.
- [x] Jalankan `php artisan test --filter=DatabaseConstraintsTest` dari backend dan catat kegagalan constraint yang belum diterapkan.
- [x] Implementasikan migration, model, relasi, foreign key, indeks, dan constraint sesuai dokumen 05. Periksa migration naik/turun di database test sekali pakai.
- [x] Jalankan test sampai lulus; GET health tidak menampilkan konfigurasi/credential. Commit fondasi setelah hasil lulus.

### B-02: Login, aktivasi, reset, dan policy akun

**Depends:** B-01.

**Files:** Create backend/app/Actions/Accounts/{CreateAccount,ActivateAccount,ResetAccountPassword,DeactivateAccount,RevealTemporaryCredential}.php; backend/app/Http/Controllers/Api/AuthController.php; backend/app/Http/Middleware/EnsureActiveAccount.php; backend/app/Policies/{StudentPolicy,PointRecordPolicy,AttachmentPolicy}.php; backend/app/Console/Commands/CreateAdmin.php; Test backend/tests/Feature/{RoleAuthenticationTest,TemporaryCredentialTest}.php; Modify backend/routes/api.php.

**Interfaces:** CreateAccount::execute(User $admin, array $attributes): User; ActivateAccount::execute(User $user, string $newPassword): User; ResetAccountPassword::execute(User $admin, User $target, string $reason): void; DeactivateAccount::execute(User $admin, User $target, string $reason): void; RevealTemporaryCredential::execute(User $actor, Student $student): array{password:string,expires_at:string}. Produces endpoint auth/me dan alias login pada dokumen 06.

- [x] Tulis test login siswa tidak diterima guru, NIP/kode ambigu ditolak, pending_activation hanya boleh ganti password, temporary expired 24 jam ditolak, password aktif tidak terungkap, dan admin aktif terakhir tidak dinonaktifkan.
- [x] Pin hasil login terbatas: `self::assertTrue($response->json('data.must_change_password'));`; pada test, daftarkan route probe khusus middleware aktif untuk membuktikan akun belum aktivasi mendapat `assertForbidden()` tanpa menunggu endpoint QR B-06. User siswa pada endpoint guru mendapat `assertUnauthorized()`.
- [x] Jalankan `php artisan test --filter='RoleAuthenticationTest|TemporaryCredentialTest'` dan lihat gagal sebelum action/policy tersedia.
- [x] Implementasikan hash, salinan sementara terenkripsi, TTL, penghapusan saat aktivasi, sesi Sanctum 7 hari, rate limit, autentikasi ulang reveal/reset, serta audit aman. Policy file menangani guru semua siswa aktif dan arsip catatan sendiri.
- [x] Verifikasi test lulus dan reset/deactivate menolak token lama. Commit hasil akun; tidak ada password contoh pada seeder/.env.example.

### B-03: Akademik, penempatan, dan siklus siswa

**Depends:** B-02.

**Files:** Create backend/app/Actions/Academic/{OpenSemester,EnrollStudent,MoveStudent,ChangeStudentStatus}.php; backend/app/Services/Academic/CurrentEnrollment.php; Test backend/tests/Feature/AcademicLifecycleTest.php.

**Interfaces:** OpenSemester::execute(User $admin, Semester $semester): Semester; MoveStudent::execute(User $admin, Student $student, string $classroomId, string $effectiveAt): Enrollment; ChangeStudentStatus::execute(User $admin, Student $student, string $status, string $reason): Student; CurrentEnrollment::forStudent(Student $student, Semester $semester, string $at): ?Enrollment.

EnrollStudent::execute(User $admin, Student $student, Semester $semester, string $classroomId, string $effectiveAt): Enrollment menyiapkan penempatan awal pada semester draft/active untuk A-02/B-09. MoveStudent menangani perpindahan penempatan pada semester aktif; ChangeStudentStatus hanya transferred/graduated. EnrollStudent memakai validasi tahun ajaran/kelas aktif/rentang semester/tanpa overlap dan urutan lock yang sama; akhir penempatan berupa batas eksklusif setelah end_date pada timezone sekolah.

- [x] Tulis test semester rentang tumpang tindih/di luar tanggal ditolak, dua penempatan overlap ditolak, perpindahan mengakhiri penempatan lama, dan pindah/lulus mencabut akun tanpa menghapus record.
- [x] Pin riwayat: `self::assertSame($oldEnrollment->id, $record->fresh()->enrollment_id);` setelah pindah kelas; token siswa lulus pada endpoint /me mendapat `assertUnauthorized()`. Pembacaan record guru setelah siswa lulus diuji B-08 ketika endpoint riwayat sudah tersedia.
- [x] Jalankan `php artisan test --filter=AcademicLifecycleTest` dan konfirmasi gagal.
- [x] Implementasikan action dan lock berurutan: semester, siswa, lalu penempatan/resource domain. Samakan urutan dengan B-06 sampai B-08. Perubahan semester massal tidak menghapus record.
- [x] Jalankan test hingga lulus termasuk perlombaan pindah kelas vs pencatatan pada tahap B-07; commit action akademik.

### B-04: Daftar poin berversi

**Depends:** B-02.

**Files:** Create backend/app/Actions/Catalog/{CreatePointType,RevisePointType,DeactivatePointType}.php; backend/app/Http/Controllers/Api/PointTypeController.php; Test backend/tests/Feature/PointCatalogTest.php; Modify backend/routes/api.php.

**Interfaces:** CreatePointType::execute(User $admin, array $attributes): PointType; RevisePointType::execute(User $admin, PointType $type, array $attributes): PointTypeVersion; DeactivatePointType::execute(User $admin, PointType $type): void. GET /teacher/point-types menghasilkan id, version_id, category, name, points, description untuk jenis aktif.

- [x] Tulis test guru tidak mengubah katalog, points 0/negatif ditolak, revisi membuat versi baru, jenis nonaktif tidak muncul, dan kategori jenis yang sudah digunakan tidak berubah.
- [x] Pin versi: `self::assertSame(2, $newVersion->version); self::assertSame(10, $oldVersion->fresh()->points);` ketika nilai baru 15.
- [x] Jalankan `php artisan test --filter=PointCatalogTest`; implementasikan action/admin authorization dan resource daftar aktif.
- [x] Jalankan kembali sampai lulus; commit katalog tanpa mengubah snapshot catatan lama.

### B-05: Query total, kebijakan ambang, dan tindak lanjut

**Depends:** B-03/B-04.

**Files:** Create backend/app/Services/Points/{PointTotals,ThresholdEvaluator}.php; backend/app/Actions/Points/{SetThresholdPolicy,RecordFollowUp}.php; Test backend/tests/Feature/PointTotalsAndThresholdTest.php.

**Interfaces:** PointTotals::forStudent(Student $student, Semester $semester): array{achievement:int,violation:int}; ThresholdEvaluator::evaluate(Student $student, Semester $semester): void; SetThresholdPolicy::execute(User $admin, Semester $semester, array $levels, string $reason): ThresholdPolicy; RecordFollowUp::execute(User $admin, ThresholdAlert $alert, array $attributes): FollowUp. Evaluator dipanggil setelah revision ditulis dalam transaksi yang memegang lock siswa.

- [x] Tulis fixture catatan dengan prestasi 10, pelanggaran 15, revisi pelanggaran menjadi 5, satu cancellation, dan semester lain. Pin `self::assertSame(['achievement'=>10,'violation'=>5], $totals);`.
- [x] Uji batas tepat sama dengan threshold, dua threshold terlewati, turun karena koreksi, naik kembali tanpa duplikasi, dan kebijakan baru menggantikan status versi lama tanpa menghapus follow-up.
- [x] Jalankan `php artisan test --filter=PointTotalsAndThresholdTest` dan lihat gagal; implementasikan query latest revision dan evaluator tanpa cached total yang dapat basi.
- [x] Verifikasi test lulus, action hanya admin, tindak lanjut completed tidak mengurangi nilai. Commit total/ambang.

### B-06: QR dan pemilihan manual

**Depends:** B-03/B-05.

**Files:** Create backend/app/Actions/Points/{IssueStudentQr,RedeemStudentQr,SelectStudentManually}.php; backend/app/Http/Controllers/Api/{StudentQrController,TeacherStudentController,StudentSelectionController}.php; Test backend/tests/Feature/{QrSelectionTest,QrConcurrencyTest}.php; Modify backend/routes/api.php.

**Interfaces:** IssueStudentQr::execute(User $studentUser): array{token:string,expires_at:string,server_time:string}; RedeemStudentQr::execute(User $teacherUser, string $token, string $requestKey): StudentSelection; SelectStudentManually::execute(User $teacherUser, Student $student): StudentSelection. Produces QR/redeem/search/profile/select endpoints pada dokumen 06.

- [x] Tulis test dengan clock beku: QR valid sebelum 20 detik, expired pada 20 detik; QR baru mencabut lama; manual dan QR menuju student/enrollment sama; selection expiry 5 menit.
- [x] Pin token sekali pakai melalui dua koneksi PostgreSQL: satu redeem sukses, satu konflik. Retry guru/request_key sama `self::assertSame($firstSelection->id, $retrySelection->id);`.
- [x] Jalankan `php artisan test --filter='QrSelectionTest|QrConcurrencyTest'` sebelum implementasi; cek failure.
- [x] Implementasikan 32-byte token, hash, lock, redemption retry, status aktif, semester/penempatan, response no-store, dan pencarian berpagination. Token mentah tidak masuk log.
- [x] Verifikasi test lulus termasuk token milik siswa inactive; commit QR/manual.

### B-07: Pencatatan, bukti privat, dan idempotensi

**Depends:** B-04/B-05/B-06.

**Files:** Create backend/app/Actions/Points/{RecordPoint,StageEvidence}.php; backend/app/Http/Controllers/Api/{PointRecordController,AttachmentController}.php; backend/app/Http/Requests/StorePointRecordRequest.php; backend/app/Http/Resources/PointRecordResource.php; Test backend/tests/Feature/{RecordPointTest,RecordPointConcurrencyTest,PrivateEvidenceTest}.php; Modify backend/routes/api.php.

**Interfaces:** RecordPoint::execute(User $teacherUser, array $input, string $idempotencyKey): PointRecord; StageEvidence::execute(User $teacherUser, UploadedFile $file): Attachment. input hanya selection_id, point_type_version_id, description, attachment_id nullable. PointRecordResource memuat id, latest_revision, source, occurred_at, student, teacher, semester, review_status. API menyertakan summary dari PointTotals.

- [ ] Tulis test validasi keterangan 5-1.000, JPG/PNG 5 MB, owner lampiran, tamper student/points, selection expired/used, katalog berubah, dan penempatan berubah.
- [ ] Pin retry: `self::assertSame($firstRecord->id, $retryRecord->id); self::assertSame(1, PointRecord::query()->where('idempotency_key', $key)->count());`; payload berbeda `assertStatus(409)`.
- [ ] Jalankan `php artisan test --filter='RecordPointTest|RecordPointConcurrencyTest|PrivateEvidenceTest'` dan lihat kegagalan.
- [ ] Implementasikan transaction, urutan lock B-03, snapshot/revision pertama, konsumsi selection, ThresholdEvaluator, audit, serta outbox event unik. Idempotensi diperiksa sebelum penolakan consumed selection.
- [ ] Implementasikan lookup /teacher/record-attempts/{key} dan streaming bukti lewat policy. Bersihkan metadata gambar, jangan buat URL storage publik.
- [ ] Verifikasi dua record paralel memicu threshold gabungan, retry setelah commit aman, dan file siswa lain ditolak; commit pencatatan.

### B-08: Riwayat, keberatan, dan koreksi

**Depends:** B-07.

**Files:** Create backend/app/Actions/Reviews/{SubmitReviewRequest,DecideReviewRequest,ReviseRecord}.php; backend/app/Http/Controllers/Api/{StudentSummaryController,StudentRecordController,TeacherRecordController,ReviewRequestController}.php; Test backend/tests/Feature/{StudentSummaryAccessTest,RecordHistoryAccessTest,ReviewDecisionTest}.php; Modify backend/routes/api.php dan backend/app/Http/Controllers/Api/AuthController.php.

**Interfaces:** SubmitReviewRequest::execute(User $requester, PointRecord $record, string $kind, string $reason, array $proposedChange): ReviewRequest; DecideReviewRequest::execute(User $admin, ReviewRequest $request, string $decision, string $reason, int $expectedRevision, array $changes): ReviewRequest; ReviseRecord::execute(User $admin, PointRecord $record, string $reason, int $expectedRevision, array $changes): PointRecord. History endpoints memakai resource B-07.

StudentSummaryController menyediakan GET /student/summary sesuai dokumen 06: dua total dari PointTotals B-05, semester, dan status ambang terbaru milik siswa login. Siswa lain tidak dapat dipilih melalui input klien. Uji akses role/aktivasi, total efektif setelah koreksi, dan kondisi belum ada semester aktif; jangan menampilkan nol palsu sebagai data yang belum tersedia. Ini melengkapi endpoint rancangan yang belum dialokasikan pada tugas layanan total B-05.

Lengkapi respons GET /me untuk akun aktif dengan semester dan penempatan terkait dari B-03 sesuai dokumen 06. Mode pending tetap hanya mengungkap identitas aktivasi. Uji metadata siswa/guru, penempatan kosong, dan mode pending pada StudentSummaryAccessTest; jangan mengubah kontrak login/aktivasi B-02.

decision adalah approve/reject. changes/proposedChange memakai operation cancel/revise, point_type_version_id opsional, description opsional, dan attachment_id opsional; setidaknya satu perubahan sah diperlukan untuk revise. Pengajuan siswa hanya reason dan proposedChange kosong. Reject memakai changes kosong; approve harus menentukan cancel/revise secara eksplisit. Field identitas siswa/guru/semester tidak diizinkan.

- [ ] Tulis test siswa hanya record sendiri; guru hanya meminta koreksi record sendiri; guru tetap baca record sendiri siswa lulus; satu pending; pending/ditolak tidak mengubah total; cancellation mengecualikan total.
- [ ] Pin immutable owner: `self::assertSame($student->id, $record->fresh()->student_id);`; dua admin memutuskan expectedRevision sama: kedua `assertStatus(409)` dan jumlah revision tidak bertambah dua kali.
- [ ] Jalankan `php artisan test --filter='RecordHistoryAccessTest|ReviewDecisionTest'`; implementasikan action append-only, lock/version conflict, update threshold/outbox atomik, serta keputusan reason wajib.
- [ ] Jalankan sampai lulus untuk koreksi semester closed dan salah siswa yang harus dibatalkan, bukan dipindah; commit pemeriksaan.

### B-09: Import dan pengelolaan akun massal

**Depends:** B-02/B-03.

**Files:** Create backend/app/Actions/Imports/{PreviewAccountsImport,CommitAccountsImport}.php; backend/app/Services/Imports/AccountImportReader.php; backend/resources/import-templates/{students.csv,teachers.csv}; Test backend/tests/Feature/AccountImportTest.php.

**Interfaces:** PreviewAccountsImport::execute(User $admin, UploadedFile $file, string $entityType, ?Semester $semester): ImportBatch; CommitAccountsImport::execute(User $admin, ImportBatch $batch): ImportBatch. Preview menyimpan rows/errors privat; commit memakai CreateAccount B-02 dan EnrollStudent B-03. XLSX template dihasilkan endpoint admin, bukan binary mock dalam repo.

- [ ] Tulis fixture CSV/XLSX NISN "0012345678", duplicate dalam file/database, guru tanpa NIP, kode kelas salah, formula berbahaya, file melebihi 10 MB/5.000 baris, dan batch admin lain.
- [ ] Pin identifier `self::assertSame('0012345678', $student->nisn);`; satu row invalid menghasilkan `self::assertSame(0, $createdAccountCount);`.
- [ ] Jalankan `php artisan test --filter=AccountImportTest`; implementasikan reader terbatasi, preview, validasi ulang, batch atomik/idempotent, dan hasil tanpa password pada error/log.
- [ ] Uji referensi kelas berubah setelah preview dan gangguan commit; verifikasi tidak ada import parsial. Commit import dengan pemeriksaan dependency parsing XLSX yang dipilih.

### B-10: Query laporan dan ekspor

**Depends:** B-05/B-08.

**Files:** Create backend/app/Services/Reports/PointReportQuery.php; backend/app/Actions/Reports/RequestReportExport.php; backend/app/Jobs/GeneratePointReport.php; backend/resources/views/reports/points.blade.php; Test backend/tests/Feature/PointReportTest.php.

**Interfaces:** PointReportQuery::build(array $filters): array{summary:array,rows:array,generated_at:string}; RequestReportExport::execute(User $admin, array $filters, string $format): ReportExport; GeneratePointReport::handle(): void. format adalah xlsx/pdf; hanya admin pemilik export dapat mengunduh file privat.

- [ ] Tulis fixture siswa pindah kelas dengan catatan kedua kelas, cancelled record, revision nilai, identifier nol awal, dan teks berawalan =/+/-/@.
- [ ] Pin rekap kelas memakai enrollment historis: `self::assertSame(10, $oldClassReport['summary']['violation']);` ketika kelas baru memiliki 5 poin terpisah. Uji filter identik menghasilkan nilai sama pada layar/data XLSX/PDF.
- [ ] Jalankan `php artisan test --filter=PointReportTest`; implementasikan snapshot konsisten saat generation, escape text, metadata filter/timezone, queue status, dan expiry 7 hari.
- [ ] Verifikasi unduhan non-admin ditolak; render contoh PDF panjang pada task I-02 sebelum menandai kualitas ekspor selesai. Commit laporan.

### B-11: Outbox, notifikasi, dan cleanup

**Depends:** B-07/B-08/B-10.

**Files:** Create backend/app/Services/Notifications/{OutboxDispatcher,FcmGateway}.php; backend/app/Jobs/DispatchCivoraNotifications.php; backend/app/Console/Commands/CleanupTemporaryResources.php; backend/app/Http/Controllers/Api/{NotificationController,DeviceTokenController}.php; Test backend/tests/Feature/{NotificationDeliveryTest,TemporaryCleanupTest}.php; Modify backend/routes/{api.php,console.php}.

**Interfaces:** OutboxDispatcher::dispatch(OutboxEvent $event): void; FcmGateway::send(string $deviceToken, array $publicPayload): void; CleanupTemporaryResources::handle(): int. GET/mark read/device endpoints mengikuti dokumen 06. Gateway dimock dalam test otomatis; FCM nyata diperiksa pada I-01.

- [ ] Tulis test outbox retry tidak menggandakan in-app notification, kegagalan push tidak rollback record, token logout/invalid tidak dipakai, serta inactive user tidak menerima push.
- [ ] Pin deduplikasi `self::assertSame(1, Notification::query()->where('event_key', $eventKey)->count());`; simulated FCM failure tidak mengubah jumlah PointRecord.
- [ ] Tulis test cleanup: selection yang direferensikan record tetap ada; file staged yatim 24 jam dan export 7 hari dibersihkan; file path bukan input pengguna.
- [ ] Jalankan `php artisan test --filter='NotificationDeliveryTest|TemporaryCleanupTest'`; implementasikan worker, retry terbatas/backoff, session device binding, safe public message, dan scheduler cleanup.
- [ ] Jalankan seluruh `php artisan test` dan dokumentasikan queue/scheduler pada docs/development.md. Commit; tidak menandai push produksi siap tanpa I-01.
