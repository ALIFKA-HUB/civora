# Civora Mobile Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Membuat Civora Siswa dan Civora Guru sebagai dua aplikasi Android yang menjalankan alur server yang sama.

**Architecture:** Dua entry point Flutter berbagi API client, DTO, session, dan komponen tema. State controller per fitur; validasi client memberi umpan balik, sementara server tetap menjadi sumber kebenaran untuk role/poin/QR.

**Tech Stack:** Flutter, Material 3, secure storage Android, plugin QR/kamera, image picker, Firebase Messaging; dependency diverifikasi saat B-01/M-01.

**Spec:** [Indeks](../README.md), [alur](../03-alur-pengguna.md), [API](../06-arsitektur-api-keamanan.md), [UI](../07-desain-ui.md).

## Global Constraints

Seluruh [global constraints](../TASKS.md#global-constraints) berlaku. QR hitam di atas putih pada kedua tema; refresh hanya foreground. Tidak ada offline queue. Semua angka total harus berasal dari server dan tidak menampilkan 0 saat belum dimuat. Tidak ada password/token dalam log aplikasi.

## Review Focus

Jam HP berbeda dari server (M-03), respons scan/simpan hilang (M-04), kamera/FCM tidak tersedia (M-04/M-05), session habis saat membuka push (M-02/M-05), serta font besar dan sistem gelap (M-01/M-06).

## Kontrak core

DTO menggunakan ID String, timestamp DateTime UTC, dan integer points. File dto.dart mendefinisikan AccountDto(role, mustChangePassword), StudentDto, PointTypeDto(versionId), SelectionDto(id, expiresAt, student), QrDto(token, expiresAt, serverTime), SummaryDto(achievement, violation), PointRecordDto, ReviewDto, NotificationDto. Key snake_case API dipetakan satu kali menjadi camelCase Dart.

ApiException berisi statusCode, code, message, fields, requestId. ApiClient.get(String path, {Map<String, dynamic>? query}) dan ApiClient.post(String path, Map<String, dynamic> body, {String? idempotencyKey}) menghasilkan Future<Map<String, dynamic>>. Auth request tidak mengirim token akun lama. session_controller.dart mendefinisikan enum SessionState { unauthenticated, activationRequired, authenticated }; SessionController.state memakai enum tersebut. User role tidak bisa diubah oleh menu.

### M-01: Dua aplikasi dan paket core/UI

**Depends:** B-01; kontrak dokumen 06.

**Files:** Create apps/{student,teacher}/{pubspec.yaml,lib/main.dart,lib/app.dart}, packages/civora_core/{pubspec.yaml,lib/civora_core.dart,lib/src/api_client.dart,lib/src/api_exception.dart,lib/src/dto.dart,lib/src/session_controller.dart}, packages/civora_ui/{pubspec.yaml,lib/civora_ui.dart,lib/src/civora_theme.dart,lib/src/async_content.dart}; Test packages/civora_core/test/api_client_test.dart dan packages/civora_ui/test/civora_theme_test.dart; Modify docs/development.md.

**Interfaces:** Produces kontrak core di atas, CivoraTheme.light/dark, AsyncContent untuk loading/error/empty/data, ThemePreference(system/light/dark). Dua aplikasi mempunyai applicationId berbeda yang dicatat dalam docs/development.md; tidak mendaftarkan nama domain/package pihak lain sebagai identitas publik tanpa memeriksa pilihan sekolah.

- [ ] Verifikasi Flutter/Android SDK, buat dua proyek dan dua paket lokal, catat dependency/runtime, bundel font Noto Sans dengan lisensi, dan konfigurasi base URL per lingkungan.
- [ ] Tulis test mapping response/error, 401 mengakhiri session, authorization tidak bocor ke auth baru, serta tema mengikuti perubahan sistem.
- [ ] Pin response DTO `expect(summary.achievement, 10); expect(summary.violation, 5);`; payload error 409 mempertahankan code/message untuk konflik, bukan dianggap sukses.
- [ ] Jalankan `flutter test` di tiap paket sebelum implementasi core, kemudian implementasikan client/state/theme minimum sampai lulus. Hormati target sentuh 48 dan kontras dokumen 07.
- [ ] Jalankan `flutter analyze` dan build debug kedua aplikasi. Commit fondasi; shell saja tidak ditandai sebagai fitur siswa/guru selesai.

### M-02: Login, aktivasi, password, dan routing role

**Depends:** M-01/B-02.

**Files:** Create packages/civora_ui/lib/src/forms/{login_form,password_form}.dart; apps/student/lib/features/auth/auth_screen.dart; apps/teacher/lib/features/auth/auth_screen.dart; apps/{student,teacher}/lib/features/profile/profile_screen.dart; Test apps/{student,teacher}/test/auth_flow_test.dart; Modify packages/civora_core/lib/src/session_controller.dart dan apps/{student,teacher}/lib/app.dart.

**Interfaces:** SessionController.login(String identifier, String password, {required String role}): Future<void>; SessionController.changePassword(String currentPassword, String newPassword): Future<void>; SessionController.logout(): Future<void>. role selalu dikunci entry point app sebagai student/teacher, bukan input siswa/guru.

- [ ] Tulis test password sementara mengarahkan aktivasi, login role salah ditolak, secure token dipulihkan hanya untuk app yang sesuai, logout membersihkan session/device binding, dan expired session kembali login.
- [ ] Pin `expect(session.state, SessionState.activationRequired);` untuk must_change_password=true; tombol QR/catat tidak tersedia sebelum aktivasi.
- [ ] Jalankan `flutter test test/auth_flow_test.dart` di masing-masing app; implementasikan layar login/aktivasi/profile, secure storage, field password, pesan bantuan akun, dan validasi 12 karakter.
- [ ] Jalankan test dan analyze sampai lulus; jangan menyimpan password awal/pribadi sebagai preferensi biasa. Commit autentikasi dua app.

### M-03: Siswa: ringkasan, QR, riwayat, dan keberatan

**Depends:** M-02/B-06/B-08.

**Files:** Create apps/student/lib/features/{home/home_screen.dart,qr/qr_screen.dart,qr/qr_controller.dart,records/records_screen.dart,records/record_detail_screen.dart,reviews/objection_screen.dart}; Test apps/student/test/{qr_flow_test.dart,student_record_flow_test.dart}; Modify apps/student/lib/app.dart.

**Interfaces:** QrController.refresh(): Future<QrDto>; QrController.setForeground(bool active): void; QrController.dispose(): void. Ringkasan/riwayat/detail memakai endpoint student dokumen 06 dan PointRecordDto; keberatan mengirim reason, tidak mengubah total client sebelum server setuju.

- [ ] Tulis test refresh setiap 20 detik berdasarkan serverTime, background menghentikan timer, foreground meminta baru, offline tidak menunjukkan QR lama aktif, dan dispose tidak meninggalkan timer.
- [ ] Pin jam HP maju 10 menit tetap memiliki countdown valid dari selisih server; `expect(qrVisibleAsActive, false);` setelah expiresAt tanpa refresh berhasil.
- [ ] Tulis test dua total terpisah, filter semester, bukti sendiri, satu pending objection, dan pending tidak mengurangi total.
- [ ] Jalankan `flutter test test/qr_flow_test.dart test/student_record_flow_test.dart`; implementasikan empat tab dan layar terkait, hitung mundur aksesibel, form alasan 5-1.000, serta status keputusan/revisi.
- [ ] Jalankan test/analyze dan periksa QR pada HP dalam terang/gelap. Commit siswa; scanning QR nyata diverifikasi M-04/I-01.

### M-04: Guru: scan, pencarian, pencatatan, dan koreksi

**Depends:** M-02/B-06/B-07/B-08.

**Files:** Create apps/teacher/lib/features/{home/home_screen.dart,students/student_search_screen.dart,students/student_detail_screen.dart,scanner/scanner_screen.dart,points/record_point_controller.dart,points/record_point_screen.dart,points/record_summary_screen.dart,records/teacher_records_screen.dart,reviews/correction_screen.dart}; Test apps/teacher/test/{teacher_selection_test.dart,record_retry_test.dart,teacher_correction_test.dart}; Modify apps/teacher/lib/app.dart.

**Interfaces:** RecordPointController.selectQr(String token, String requestKey): Future<SelectionDto>; selectManual(String studentId): Future<SelectionDto>; submit(): Future<PointRecordDto>; resolveUnknownAttempt(): Future<PointRecordDto?>. Controller menyimpan selection, draft, frozen submitted payload, dan idempotencyKey per upaya; retry tidak membuat key baru. Nilai hanya dari PointTypeDto, bukan field bebas.

- [ ] Tulis test kamera ditolak tetap bisa manual; dua callback kamera memakai satu requestKey; retry scan mengembalikan selection sama; hasil nama sama dibedakan NISN/kelas.
- [ ] Pin respons simpan hilang: `expect(createdRecordCount, 1); expect(sentIdempotencyKeys.toSet().length, 1);`; status unknown tidak membuka upaya baru sebelum resolusi.
- [ ] Tulis test katalog/semester berubah memerlukan ringkasan ulang tanpa kehilangan keterangan, selection habis setelah 5 menit meminta pilih ulang, foto >5 MB ditolak, dan koreksi tidak mengubah total sebelum keputusan.
- [ ] Jalankan `flutter test test/teacher_selection_test.dart test/record_retry_test.dart test/teacher_correction_test.dart`; implementasikan empat tab, scan/manual, profil/riwayat siswa, draft/keterangan/bukti, ringkasan, sukses, serta permintaan koreksi.
- [ ] Verifikasi record buatan guru untuk siswa lulus tetap terbaca tetapi tombol pencatatan baru ditolak server; jalankan test/analyze dan commit.

### M-05: Notifikasi, deep link aman, tema profil, dan bantuan akun

**Depends:** M-03/M-04/B-11.

**Files:** Create packages/civora_core/lib/src/notification_controller.dart; packages/civora_ui/lib/src/notification_list.dart; apps/{student,teacher}/lib/features/notifications/notifications_screen.dart; apps/teacher/lib/features/students/temporary_credential_dialog.dart; Test apps/{student,teacher}/test/notification_flow_test.dart dan apps/teacher/test/credential_help_test.dart; Modify apps/{student,teacher}/lib/features/profile/profile_screen.dart.

**Interfaces:** NotificationController.registerDevice(): Future<void>; NotificationController.openResource(NotificationDto notification): Future<void> memakai login/role dan fetch server; reveal credential mengirim current_password pada endpoint B-02 tanpa menyimpannya. Preferensi tema disimpan tanpa credential.

- [ ] Tulis test push ditolak/tidak tersedia tetap memuat in-app list, klik push ketika logout meminta login, resource siswa lain ditolak, dan logout melepas device token.
- [ ] Pin pesan push tidak memuat nama/jenis/nilai/bukti; endpoint detail hanya dipanggil sesudah session authenticated dengan role benar.
- [ ] Tulis test credential bantuan hanya temporary valid, autentikasi ulang diperlukan, dan siswa sudah aktif menampilkan status tanpa password.
- [ ] Jalankan `flutter test test/notification_flow_test.dart` pada dua app dan `flutter test test/credential_help_test.dart` pada teacher; implementasikan FCM lifecycle, read state, deep link yang diizinkan, tema profil, serta bantuan credential.
- [ ] Verifikasi credential tidak bertahan setelah dialog ditutup/background/logout; jalankan test/analyze. Commit; credential Firebase nyata dan push background diperiksa I-01.

### M-06: Pemeriksaan perangkat dan build dua aplikasi

**Depends:** M-03/M-04/M-05.

**Files:** Create apps/student/integration_test/student_workflow_test.dart; apps/teacher/integration_test/teacher_workflow_test.dart; docs/qa/mobile.md; Modify apps/{student,teacher}/android/app/build.gradle.kts atau file build yang dihasilkan versi Flutter terpilih.

**Interfaces:** Produces dua APK debug/staging terpisah dan catatan perangkat/OS yang diuji. Signing release tidak dibuat dengan credential contoh. Workflow integration mengonsumsi API staging/data sintetis, bukan mock sebagai bukti akhir.

- [ ] Tulis integration test siswa login → QR → melihat catatan/keberatan dan guru login → scan/manual → simpan → meminta koreksi.
- [ ] Jalankan `flutter analyze`, `flutter test`, dan `flutter test integration_test` di kedua app pada perangkat/emulator yang didokumentasikan.
- [ ] Periksa perangkat fisik: kamera, orientasi, keyboard, jaringan buruk, background/foreground, tema sistem, pembesaran teks, izin push, dan perangkat tanpa push yang didukung.
- [ ] Jalankan `flutter build apk --debug` di dua app; dokumentasikan identitas aplikasi, versi minimum Android berdasarkan dependency aktual, hasil, serta batasan yang ditemukan.
- [ ] Commit setelah masalah inti terselesaikan. Dua build sukses saja tidak menggantikan alur end-to-end I-01.
