# Bukti QA lintas alur Civora (I-01)

Dokumen ini mencatat bukti lokal untuk source pada basis `f17aea5` dengan Laravel Framework `13.35.0`. Seluruh data browser/API sintetis dan database yang dipakai adalah database proyek `civora_test`. Tidak ada akun sekolah nyata, QR mentah, bearer token, password sementara, FCM credential, atau project Firebase di artefak ini.

## Hasil skenario

| ID | Hasil lokal | Bukti dan batas |
| --- | --- | --- |
| IMP-01, IMP-02 | Lulus | Dusk mengimpor NISN teks dengan nol awal `0000000901`, memeriksa pratinjau tanpa error, lalu mengonfirmasi batch. |
| ACC-02 | Lulus lokal | Dusk mengambil password sementara melalui layar admin, login siswa dan guru melalui endpoint masing-masing, mengganti password, lalu memastikan akun aktif. Ini bukan autentikasi produksi. |
| QR-01, QR-03 | Lulus API | Test I-01 menerbitkan QR melalui API siswa, mengulang redeem dengan `request_key` yang sama dan memperoleh selection sama, menolak replay dengan key lain, serta menolak QR setelah 20 detik. Token tidak dicetak. Uji kamera/scan dua perangkat belum dilakukan. |
| REC-03 | Lulus API | Test sengaja mengabaikan respons POST awal setelah penyimpanan, mengambil ID yang tersimpan, lalu mengulang POST dengan `Idempotency-Key` dan payload sama. API mengembalikan catatan yang sama; hanya satu row tercatat. |
| REC-06 | Lulus API | Catatan ditolak setelah penempatan berubah. Catatan baru juga ditolak setelah semester aktif ditutup; tidak ada row parsial. |
| REV-01, REV-02 | Lulus API dan browser | Siswa mengajukan keberatan lewat API. Admin mengoreksi catatan melalui layar pemeriksaan yang memakai action `DecideReviewRequest` yang sama. Riwayat siswa dan guru menunjukkan revisi terakhir. |
| SEM-03 | Lulus API lokal | Action kelulusan menonaktifkan akun dan mencabut sesi/QR; QR baru ditolak. Guru yang membuat catatan tetap dapat membaca catatan miliknya dari arsip. |
| REC-05 / akses lampiran silang | Lulus API lokal | Pencatatan menolak lampiran milik akun admin dan tidak membuat catatan. |
| NOT-01, NOT-02 | Lulus lokal terbatas | Outbox ada setelah transaksi catatan dan sebelum job antrean dijalankan. Test menjalankan `queue:work --once` sebagai proses PHP terpisah, kemudian menjalankan job yang sama setelah worker pertama berhenti; event selesai dan hanya satu notifikasi in-app ada. `NotificationDeliveryTest` menguji payload publik, retry, dan deduplikasi dengan gateway mock. Ini tidak membuktikan pengiriman push. |
| Ringkasan | Lulus API lokal | Setelah revisi: `achievement = 10` dan `violation = 5`, dua total kategori terpisah. Revisi awal pelanggaran 15 menjadi 5 tetap ada pada histori. |
| ACC-05 / lintas perangkat | Tidak dinilai ulang di I-01 | Batas akses ID/bukti dan race keputusan memiliki test terfokus pada task pemilik B-07/B-08. QR serentak dua guru memiliki `QrConcurrencyTest`; tidak diulang pada run I-01 ini. |

## Perintah dan hasil

Semua command dijalankan dari direktori root Civora dengan wrapper PHP proyek; wrapper menjalankan Artisan dari `backend` dan mengarah ke runtime/database lokal Civora.

| Command | Exit | Hasil |
| --- | ---: | --- |
| `.\ops\dev\php.ps1 artisan --version` | 0 | Laravel Framework 13.35.0 |
| `.\ops\dev\php.ps1 artisan test --filter=CivoraEndToEndApiTest` | 0 | 4 test, 56 assertion. Termasuk worker antrean subprocess. |
| `$env:APP_ENV = $null; .\ops\dev\php.ps1 artisan dusk --filter=AdminSchoolWorkflowTest --without-tty` | 0 | 1 test browser, 16 assertion. Chromium mengunjungi import, detail/aktivasi, API, pemeriksaan admin, dan laporan. |
| `.\ops\dev\php.ps1 artisan test --filter=NotificationDeliveryTest` | 0 | 4 test, 36 assertion. Gateway FCM hanya mock. |
| `.\ops\dev\php.ps1 vendor/bin/pint --test tests/Feature/CivoraEndToEndApiTest.php tests/Browser/AdminSchoolWorkflowTest.php` | 0 | Pint lulus pada kedua file test. |
| `git diff --cached --check` | 0 | Tidak ada whitespace error pada perubahan yang akan di-commit. |

Run pertama untuk test I-01 berisi 4 test: 1 lulus dan 3 gagal karena harness memakai auth guard lintas token tanpa reset, clock tes kembali ke waktu host sehingga token sintetis kedaluwarsa, dan histori arsip dipanggil melalui endpoint yang memang membatasi akses ke siswa sekolah aktif. Test diperbaiki agar meniru pergantian actor dengan benar, mempertahankan clock sintetis, dan memakai endpoint histori milik guru. Tidak ada source produksi yang diubah untuk menutupi kegagalan test tersebut. Run terfokus berikutnya menjadi hijau.

Server browser berjalan pada loopback `127.0.0.1:8031` dengan environment testing dan database proyek. Test worker menggunakan tabel antrean database di `civora_test`; tidak ada koneksi ke FCM/Firebase karena tidak ada token perangkat pada skenario subprocess. Data migrasi Dusk bersifat disposable.

## Pemeriksaan yang masih tertunda

- Jalankan `flutter test integration_test/student_workflow_test.dart --dart-define-from-file=../../docs/qa/mobile.student.json` dari `apps/student` dan `flutter test integration_test/teacher_workflow_test.dart --dart-define-from-file=../../docs/qa/mobile.teacher.json` dari `apps/teacher` ketika Android fisik atau AVD terhubung. Pemeriksaan M-06 sebelumnya gagal dijalankan karena tidak ada perangkat/AVD yang tersedia; hasil API desktop tidak menggantikannya.
- Scan QR dengan kamera pada dua perangkat, lifecycle/background/foreground, dan izin notifikasi Android tetap belum diuji.
- Firebase project dan konfigurasi resmi belum tersedia. Push nyata, retry provider, dan penerimaan pada perangkat masih pending. Mock hanya menguji bentuk payload/retry gateway.
- APK tidak dibangun ulang untuk I-01 karena tidak ada source integrasi mobile yang berubah.

Status I-01 tetap menunggu bukti perangkat dan push nyata. Hasil lokal di atas tidak memenuhi gate lintas perangkat/Firebase untuk menyatakan I-01 Lulus.
