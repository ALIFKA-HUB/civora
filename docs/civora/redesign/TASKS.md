# Rencana implementasi redesign Civora

> **Untuk implementer:** Baca [spesifikasi](visual-spec.md), [cakupan](screen-coverage.md), [verifikasi](verification-goals.md), dan tugas dasar yang tercantum sebelum mengubah kode. Setiap RD memakai satu implementer dan reviewer terpisah. Jalankan verifikasi relevan, catat exit code, tunggu review lulus, lalu commit/push perubahan bermakna ke `codex/civora-implementation`. Checkbox di bawah sengaja belum dicentang.

**Goal:** Terapkan visual yang disetujui tanpa mengubah domain, endpoint, action, akses, urutan field, route, atau state.

**Architecture:** Sesuaikan token di paket UI/mobile dan CSS/theme admin yang ada. Implementasikan layar melalui komponen dan action yang sudah tersedia; tidak menambah library ikon/font atau mengubah kontrak server.

**Tech Stack:** Flutter/Dart, Material 3, Laravel/Livewire/Tailwind, CSS; toolchain proyek yang sudah disiapkan.

**Spec:** [Spesifikasi visual](visual-spec.md), [cakupan layar](screen-coverage.md), [desain UI produk](../07-desain-ui.md).

## Global constraints

- Pertahankan backend endpoints, Livewire actions, role/access, routes, navigasi/tab, urutan field, idempotency, dan stale-session protection.
- Latar terang `#F5F5F6`, kartu data putih, panel emphasis `#E3E5E8`; teks utama `#151719`, secondary `#45484D`; teks teal terang `#0B625A`.
- Tema gelap memakai nilai pada `visual-spec.md`; tombol utama/nav aktif memakai fill `#E3E5E8` dengan foreground `#172225`; QR hitam pada putih dengan quiet zone dalam kedua tema.
- Noto Sans dan keluarga ikon proyek tetap dipakai; tidak ada dependency UI baru tanpa keputusan.
- Target sentuh 48 px; radius kartu 20, emphasis 24, input 12; spasi 4/8/12/16/24/32/48; motion 150–220 ms dan hormati reduced motion.
- Konsep sintetis tidak membuktikan akurasi piksel, perilaku, kamera, push, atau kebijakan sekolah.
- RD-01 dan RD-02 lulus review implementasi lokal; RD-03–RD-04 belum lulus. Acceptance perangkat tetap terpisah. Pekerjaan visual ini tidak mengubah status 21 dari 26 task utama yang lulus.

## Review focus

1. QR concept kehilangan NISN: pastikan NISN dan quiet zone tetap hadir; cakupan QR M-03/M-04.
2. Retry/unknown save dapat mengirim duplikat setelah perubahan widget; pastikan retry menyimpan key dan payload yang sama; `apps/teacher/test/record_retry_test.dart`.
3. Perubahan gaya underline atau nav dapat mengubah semantik/rute tanpa sengaja; pastikan route/tab lama tetap sama; widget tests kedua app.
4. Teks/ikon semantik teal dan border/focus mungkin kurang kontras pada permukaan perak; hitung token aktual dan periksa state fokus/disabled.
5. Kamera denied/unavailable dan state offline/error dapat tampak siap padahal tidak; manual fallback tetap terlihat dan state tidak menyebut sukses; scanner/widget test dan acceptance perangkat tetap terpisah.

### RD-01: Fondasi visual bersama dan layar mobile utama

**Depends:** M-01 Lulus; B-06/B-07 sudah tersedia. Tidak menunggu M-03 acceptance kamera.

**Files:** Modify `packages/civora_ui/lib/src/civora_theme.dart`, `apps/student/lib/features/home/home_screen.dart`, `apps/student/lib/features/qr/qr_screen.dart`, `apps/teacher/lib/features/home/home_screen.dart`, `apps/teacher/lib/features/scanner/scanner_screen.dart`; test yang relevan di `packages/civora_ui/test/civora_theme_test.dart`, `apps/student/test/widget_test.dart`, `apps/teacher/test/widget_test.dart`.

**Interfaces:** Hasilkan theme light/dark sesuai `visual-spec.md` dan gaya kapsul navigasi yang memakai tab/routing saat ini. QR tetap menyajikan NISN, countdown server, dan status koneksi; scanner tetap menyediakan jalur pencarian manual.

- [x] Periksa theme dan struktur route/widget yang ada; tulis/ubah test perilaku hanya untuk regresi nyata yang disentuh, bukan snapshot kosmetik.
- [x] Terapkan token bersama, kartu/radius/tombol dan nav kapsul; pastikan underline kelas tidak menavigasi dan QR menampilkan NISN.
- [x] Periksa kedua tema, target 48 px, teks besar, quiet zone hitam-putih, denied camera/manual fallback, dan reduced motion.
- [x] Jalankan `flutter test test/civora_theme_test.dart` lalu `flutter analyze` dari `packages/civora_ui`; jalankan `flutter test test/widget_test.dart test/qr_flow_test.dart` dan `flutter analyze` dari `apps/student`; jalankan `flutter test test/widget_test.dart test/teacher_selection_test.dart` dan `flutter analyze` dari `apps/teacher`. Expected: exit 0; catat command/exit code. Tidak ada perangkat fisik yang disimpulkan dari test ini.
- [x] Review independen menyetujui dan seluruh temuan blocking diperbaiki; baru setelah itu commit/push dan catat bukti. Tetap unchecked sebelum gate lulus.

### RD-02: Layar siswa/guru dan keadaan alur turunan

**Depends:** RD-01 lulus; M-02/M-03/M-04/M-05 sesuai fitur yang disentuh.

**Files:** Modify `apps/student/lib/features/auth/auth_screen.dart`, `records/records_screen.dart`, `records/record_detail_screen.dart`, `reviews/objection_screen.dart`, `notifications/notifications_screen.dart`, `profile/profile_screen.dart`; `apps/teacher/lib/features/auth/auth_screen.dart`, `students/student_search_screen.dart`, `students/student_detail_screen.dart`, `students/temporary_credential_dialog.dart`, `points/record_point_screen.dart`, `points/record_summary_screen.dart`, `records/teacher_records_screen.dart`, `reviews/correction_screen.dart`, `notifications/notifications_screen.dart`, `profile/profile_screen.dart`. Existing test files: `apps/student/test/{auth_flow_test,student_record_flow_test,notification_flow_test}.dart`; `apps/teacher/test/{auth_flow_test,student_search_test,record_retry_test,teacher_correction_test,notification_flow_test,credential_help_test}.dart`.

**Interfaces:** Semua action memakai controller/API/DTO yang ada. Field dan urutannya mengikuti `03-alur-pengguna.md`; tidak menambah navigasi atau mengubah endpoint.

- [x] Terapkan pola visual ke layar siswa/guru yang belum tercakup dalam RD-01 dan daftar turunan di `screen-coverage.md`, termasuk empty/loading/offline/error dan unknown-save lock.
- [x] Pertahankan validasi password/keterangan/bukti, state objection/correction, notifikasi login-required, autentikasi ulang, dan teks bahwa data pending belum diputuskan.
- [x] Uji regresi terarah QR selection, record retry, correction, auth/session, notification bila perubahan menyentuh state tersebut; verifikasi UI terang/gelap, teks besar, screen reader labels, focus, dan reduced motion.
- [x] Dari `apps/student`, jalankan `flutter test test/auth_flow_test.dart test/student_record_flow_test.dart test/notification_flow_test.dart` dan `flutter analyze`; dari `apps/teacher`, jalankan `flutter test test/auth_flow_test.dart test/student_search_test.dart test/record_retry_test.dart test/teacher_correction_test.dart test/notification_flow_test.dart test/credential_help_test.dart` dan `flutter analyze`. Expected: exit 0; catat hasil setelah perubahan. Pengecekan scanner/push fisik tetap dicatat di M-03/M-06/I-01.
- [x] Review terpisah lulus; commit/push bukti yang relevan; task belum dicentang sebelum gate lulus.

**Batas penerimaan:** Gate implementasi lokal RD-02 lulus. Render baru hanya detail siswa terang/gelap; audit visual seluruh layar/state, focus dan perangkat tetap RD-04, belum dinyatakan lulus.

### RD-03: Tata letak admin konsisten dan responsif

**Depends:** A-01 sampai A-05 Lulus; admin memakai Blade/Livewire/Tailwind yang sudah ada.

**Files:** Modify `backend/resources/css/civora-theme.css`, `backend/resources/css/app.css` bila relevan, dan view yang sesuai di antara `backend/resources/views/livewire/admin/{dashboard,students,student-detail,accounts-import,teachers,academic,point-types,point-records,review-queue,review-detail,thresholds,follow-ups,reports,settings,audit-log-viewer,login,activate,confirm-password}.blade.php`; test terkait `backend/tests/Browser/AdminSchoolWorkflowTest.php`, `backend/tests/Browser/AdminReviewWorkflowBrowserTest.php`, dan feature test pemilik perilaku.

**Interfaces:** Pertahankan route/action Livewire, field order, session idle/role guards, modal semantics, focus handling dan kontrak laporan/import.

- [ ] Terapkan palet/bentuk/spasi, tautan teal, state fokus/disabled, layout sidebar responsif dan kartu data berkelompok. Teks/aksi tidak disembunyikan saat viewport menyempit.
- [ ] Lengkapi layar turunan admin yang ada pada coverage, termasuk login, detail/form, import lifecycle, akademik, edit katalog/kebijakan, daftar catatan dan dialog. Tambah tema gelap hanya mengikuti tokens yang disetujui.
- [ ] Dari `backend`, jalankan `php artisan test --filter=AdminSchoolWorkflowTest`, `php artisan test --filter=AdminReviewWorkflowBrowserTest`, feature test yang dimiliki layar berubah, dan `npm run build`. Expected: seluruh command yang relevan exit 0; catat test/viewport dan hasil. Jangan menafsirkan build sebagai penerimaan layar.
- [ ] Ukur kontras pada komponen dan state aktif; periksa keyboard/focus, dialog/drawer, viewport kecil, dan reduced motion.
- [ ] Reviewer independen lulus; commit/push perubahan; task tetap unchecked sebelum gate selesai.

### RD-04: Penerimaan visual lintas permukaan dan status

**Depends:** RD-01/RD-02/RD-03 lulus; penerimaan tahap akhir mengikuti G2/G3 dan perangkat tersedia.

**Files:** Update `docs/civora/redesign/screen-coverage.md`, `docs/civora/redesign/verification-goals.md`, `docs/civora/PROGRESS.md`, dan bukti QA pada `docs/qa/` yang sudah digunakan proyek bila memang ada hasil baru.

**Interfaces:** Menghasilkan matriks bukti dengan commit/build, layar/state, tema/viewports/perangkat, command, exit code, reviewer, temuan, dan batasan. Mempertahankan acceptance kamera/push/restore/kapasitas sebagai status mandiri.

- [ ] Jalankan checklist per layar: terang/gelap sejauh tersedia, text scaling, keyboard/focus, touch, reduced motion, kontras foreground/background dan state.
- [ ] Untuk tema/status yang belum memiliki raster atau perangkat, catat “belum ditinjau/menunggu perangkat”; jangan klaim pixel accurate atau acceptance kamera/push lulus.
- [ ] Jalankan pemeriksaan regression yang diperlukan dan verifikasi link/bukti; bandingkan status old task sebelum memperbarui Progress.
- [ ] Reviewer independen mengonfirmasi cakupan dan kejujuran bukti; update GOALS/PROGRESS hanya pada hasil yang lulus gate, commit/push.
- [ ] Centang RD-04 hanya sesudah seluruh hasil visual terkait diterima reviewer dan batas eksternal tetap tercatat.
