# Checklist QA final dan keputusan release Civora

**Status:** persiapan checklist; release belum diizinkan.
**Tanggal snapshot:** 2026-10-14.
**Commit basis dokumen:** diisi pada laporan tugas setelah commit.
**Versi backend/admin / Civora Siswa / Civora Guru:** NOT CAPTURED.
**Lingkungan staging/production:** ENV NOT PROVIDED.
**Pemilik operasi / pengambil keputusan sekolah:** NOT ASSIGNED.

Gunakan data sintetis dan target terisolasi untuk pemeriksaan QA. Ikuti [runbook deployment](../operations/deployment.md), [runbook backup dan restore](../operations/backup-restore.md), [incident response](../operations/incident-response.md), [rencana kapasitas](../operations/capacity.md), [alur QA lintas platform](end-to-end.md), [QA mobile](mobile.md), [QA ekspor](exports.md), dan [verifikasi serta skenario operasi](../civora/08-verifikasi-dan-operasi.md). Hasil checklist ini mencatat bukti test; keputusan release memerlukan penerimaan sekolah dan dependency produksi secara terpisah.

## Cara mencatat

Gunakan hanya `PASS`, `FAIL`, `NOT RUN`, `BLOCKED`, atau `NOT APPLICABLE`, sertakan bukti dan pemilik. `NOT RUN`, `BLOCKED`, dan pemilik yang belum ditetapkan bukan hasil lulus. Jangan centang hanya karena UI, mock, build lokal, atau konfigurasi tanpa credential tersedia. Catat command lengkap, working directory, exit code, jumlah test/assertion, commit, versi aplikasi/backend, target DB/perangkat, dan batas bukti. Jangan memasukkan credential, key, token, QR mentah, data siswa, atau konten lampiran ke laporan.

## Penerimaan aplikasi dan operasi

Semua hasil di bawah ini saat dokumen disiapkan belum dijalankan sebagai rangkaian final.

| Pemeriksaan | Hasil | Pemilik | Bukti / versi / batas |
| --- | --- | --- | --- |
| Backend: `ops/dev/php.ps1 artisan test` dari root Civora, DB PostgreSQL disposable | NOT RUN | NOT ASSIGNED | Catat exit, jumlah test/assertion, dan commit. Jalankan suite penuh hanya pada tahap testing final. |
| Pint: `ops/dev/php.ps1 vendor/bin/pint --test` dari root Civora | NOT RUN | NOT ASSIGNED | Catat exit dan hasil. |
| Build aset admin: `npm run build` dari `backend` | NOT RUN | NOT ASSIGNED | Catat Node/build output/commit. |
| Browser admin: `APP_ENV` kosong; `ops/dev/php.ps1 artisan dusk --filter=AdminSchoolWorkflowTest --without-tty` dari root Civora | NOT RUN | NOT ASSIGNED | `AdminSchoolWorkflowTest` menjalankan browser Chromium untuk import, detail/aktivasi, API, pemeriksaan admin, dan laporan. Tambahkan pemeriksaan keyboard/fokus dan tema pada acceptance browser. Data sintetis saja. |
| Android Civora Siswa: `flutter analyze` | NOT RUN | NOT ASSIGNED | Jalankan di `apps/student`; catat Flutter/Dart/commit. |
| Android Civora Siswa: `flutter test` | NOT RUN | NOT ASSIGNED | Jalankan di `apps/student`; catat jumlah test dan exit. |
| Android Civora Guru: `flutter analyze` | NOT RUN | NOT ASSIGNED | Jalankan di `apps/teacher`; catat Flutter/Dart/commit. |
| Android Civora Guru: `flutter test` | NOT RUN | NOT ASSIGNED | Jalankan di `apps/teacher`; catat jumlah test dan exit. |
| APK debug Civora Siswa | NOT RUN | NOT ASSIGNED | `flutter build apk --debug` dari `apps/student`; rekam app ID/version/hash. Build debug bukan artefak distribusi release. |
| APK debug Civora Guru | NOT RUN | NOT ASSIGNED | `flutter build apk --debug` dari `apps/teacher`; rekam app ID/version/hash. Build debug bukan artefak distribusi release. |
| Dua workflow integration pada Android fisik/AVD | NOT RUN | NOT ASSIGNED | Jalankan command terperinci pada [QA mobile](mobile.md) terhadap API dan akun sintetis; rekam model/API/hasil untuk setiap app. |
| Kamera QR, dua target, izin kamera, rotasi/background/foreground, jaringan gagal | NOT RUN | NOT ASSIGNED | Keduanya harus diuji di perangkat Android; API/widget test tidak menggantikan scan kamera fisik. |
| Tema terang/gelap, teks besar, navigasi keyboard/fokus, izin notifikasi ditolak | NOT RUN | NOT ASSIGNED | Perangkat/browser dan hasil per platform dicatat. |
| Export QA XLSX/PDF: `ops/dev/php.ps1 artisan test --filter=ExportConsistencyTest` dari root Civora, lalu pemeriksaan file/render | NOT RUN | NOT ASSIGNED | Gunakan fixture sintetis; buka XLSX, periksa identifier/total/filter/formula; render dan inspeksi halaman PDF. `docs/qa/exports.md` adalah bukti I-02 sebelumnya, bukan pengganti rerun final pada release candidate. |
| Pengukuran kapasitas I-04 | NOT RUN | NOT ASSIGNED | Isi [formulir run kapasitas](../operations/capacity.md); anggaran performa harus disetujui terpisah. |
| Restore dari pasangan backup offsite terenkripsi pada host terisolasi | NOT RUN | NOT ASSIGNED | Ikuti runbook restore; rekam umur backup/RPO, durasi/RTO, checksum, DB+lampiran, kontrol akses, outbox tanpa replay, isolasi, dan disposal. Backup lokal atau syntax check tidak cukup. |
| Alarm dan eskalasi operasional | NOT RUN | NOT ASSIGNED | Bukti pengiriman ke penerima yang disetujui untuk worker/scheduler, backup, ruang, queue/outbox, dan kegagalan job. Alarm belum tersedia dari repo. |

Perintah mobile device menggunakan file QA lokal ignored sebagaimana dijelaskan dalam [QA mobile](mobile.md); simpan kredensial hanya pada file lokal tersebut dan jangan lampirkan file ke bukti publik. Wrapper ops/dev memakai runtime/database milik proyek. Pastikan database disposable dan identitas target terverifikasi sebelum command yang terhubung database.

Jalankan command final berikut berurutan hanya setelah tahap implementasi/preparasi selesai. Run dari PowerShell di root Civora kecuali cwd disebutkan. Isi `APP_ENV` kosong hanya untuk Dusk lokal; semua akun/record QA sintetis. Ini adalah prosedur yang akan dijalankan, bukan bukti hasil saat ini.

```powershell
# Backend suite, style, dan aset admin
./ops/dev/php.ps1 artisan test
./ops/dev/php.ps1 vendor/bin/pint --test
Push-Location backend
npm run build
Pop-Location

# Browser admin end-to-end (database/runtime lokal Civora)
$env:APP_ENV = $null
./ops/dev/php.ps1 artisan dusk --filter=AdminSchoolWorkflowTest --without-tty

# Export regression dan file QA
./ops/dev/php.ps1 artisan test --filter=ExportConsistencyTest

# QA mobile: hanya setelah runtime/database proyek civora_test terverifikasi
./ops/dev/php.ps1 artisan civora:prepare-mobile-qa-fixtures --env=testing
./ops/dev/serve.ps1 -BrowserTest -Port 8031

# Civora Siswa — cwd apps/student
Push-Location apps/student
flutter analyze
flutter test
flutter build apk --debug --dart-define=CIVORA_API_BASE_URL=http://10.0.2.2:8031/api/v1
flutter test integration_test/student_workflow_test.dart --dart-define-from-file=../../docs/qa/mobile.student.json
Pop-Location

# Civora Guru — cwd apps/teacher
Push-Location apps/teacher
flutter analyze
flutter test
flutter build apk --debug --dart-define=CIVORA_API_BASE_URL=http://10.0.2.2:8031/api/v1
flutter test integration_test/teacher_workflow_test.dart --dart-define-from-file=../../docs/qa/mobile.teacher.json
Pop-Location
```

Sebelum perintah backend yang memakai database, pastikan runtime marker dan identitas koneksi menunjuk ke database proyek disposable `civora_test`, bukan data sekolah; fixture QA menolak target tak sesuai dan file config yang sudah ada. Native integration memerlukan emulator/perangkat yang tersambung, API lokal yang berjalan, dan file konfigurasi QA ignored berisi akun sintetis. Dusk dan test tetap dinilai lewat exit code serta hasil aktual; jangan menganggap perintah sukses hanya karena proses dapat dimulai.

Untuk restore final, gunakan prosedur lengkap pada [runbook backup dan restore](../operations/backup-restore.md). Target wajib berupa instance PostgreSQL terpisah dan database baru bernama `civora_restore_YYYYMMDD`, role disposable, direktori storage privat kosong, aplikasi terisolasi tanpa route/credential production, dan egress FCM diblokir. Verifikasi host/port/data directory/database/owner dengan `psql` sebelum menjalankan Artisan; worker dan scheduler tetap berhenti sepanjang drill. Jangan menjalankan drill terhadap production, jangan replay pending outbox, dan jangan menyalin state hasil restore ke layanan live. Jika identitas target atau custody key tidak terbukti, hentikan drill dan catat `BLOCKED`.

## Penerimaan sekolah

Admin sekolah harus memeriksa nilai dan proses yang dimiliki sekolah. Dokumentasi ini tidak menetapkan aturan sekolah. Simpan catatan keputusan tanpa menyalin data personal.

| Review sekolah | Hasil | Reviewer/tanggal | Catatan/keputusan sekolah |
| --- | --- | --- | --- |
| Contoh katalog prestasi dan pelanggaran | NOT RUN | NOT ASSIGNED | Nilai/label yang dipakai sekolah: NOT PROVIDED. |
| Ambang dan tindak lanjut | NOT RUN | NOT ASSIGNED | Persetujuan aturan sekolah: NOT PROVIDED. |
| Template import serta identifier teks/leading zero | NOT RUN | NOT ASSIGNED | Format/field sekolah: NOT PROVIDED. |
| Semester, tahun ajaran, kelas, jurusan, dan perubahan penempatan historis | NOT RUN | NOT ASSIGNED | Kalender/struktur sekolah: NOT PROVIDED. |
| Bantuan akun, aktivasi, reset, penonaktifan, dan custody password sementara | NOT RUN | NOT ASSIGNED | Pemilik layanan bantuan dan prosedur terotorisasi: NOT ASSIGNED. |
| Contoh laporan dan ekspor yang dipakai sekolah | NOT RUN | NOT ASSIGNED | Filter, kolom, privasi, dan masa penyimpanan: NOT PROVIDED. |
| Persetujuan anggaran kinerja dan skenario concurrency | NOT RUN | NOT ASSIGNED | Gunakan hasil/angka ukur dari [rencana kapasitas](../operations/capacity.md); tidak ada batas yang disetujui saat ini. |

## Dependency produksi

Setiap dependency harus memiliki pemilik, bukti aman, versi/masa berlaku, dan keputusan lingkungan. Jangan tulis nilainya ke checklist.

| Dependency | Status | Pemilik | Bukti atau tindakan |
| --- | --- | --- | --- |
| Tujuan staging/production dan operator berwenang | NOT PROVIDED | NOT ASSIGNED | Environment terpisah dengan database, credential, dan disk privat tersendiri. |
| Domain sekolah dan TLS valid | NOT PROVIDED | NOT ASSIGNED | DNS/pemilik domain, sertifikat, renewal, HTTPS dan verifikasi chain. |
| Backup terenkripsi offsite, retention, akses dan key custody | NOT PROVIDED | NOT ASSIGNED | Backup harian/umur maksimal target 24 jam; bukti drill restore terisolasi. RPO 24 jam/RTO 4 jam target rancangan, belum terbukti. |
| `APP_KEY`, DB credential, encryption/decryption key, dan secret custody | NOT PROVIDED | NOT ASSIGNED | Custodian dan prosedur pemulihan terpisah; tidak boleh di repo/log/checklist. |
| Firebase project, dua app registration, konfigurasi dan FCM service account | NOT PROVIDED | NOT ASSIGNED | Firebase belum tersedia. Mock/test lokal tidak membuktikan push nyata; uji push perlu akun Firebase dan perangkat staging yang disetujui. |
| Signing key dan konfigurasi release kedua aplikasi | NOT PROVIDED | NOT ASSIGNED | Debug key bukan release signing. Pemilik key dan rotasi/custody sekolah belum ditentukan. |
| Jalur distribusi dan penerima Android | NOT PROVIDED | NOT ASSIGNED | Internal/Play Store belum dipilih; package registration dan akses distribusi belum disediakan. |
| Admin sekolah, akun admin aman, dan prosedur penerimaan | NOT PROVIDED | NOT ASSIGNED | Admin aktual dan mekanisme serah terima credential belum ditetapkan. |
| Pemilik operasi untuk DB, web/PHP, worker, scheduler, disk, alarm dan insiden | NOT ASSIGNED | NOT ASSIGNED | Jadwal on-call, penerima alarm dan eskalasi harus disepakati. |
| Kapasitas server dan headroom | NOT DECIDED | NOT ASSIGNED | Tunggu hasil ukur pada data sintetis dan skenario sekolah yang disetujui. |

## Gerbang keputusan release

Keputusan pengujian dan keputusan release disimpan terpisah. `GO` hanya dapat dipilih pengambil keputusan sekolah setelah seluruh blocker yang berlaku memiliki bukti lulus, penerimaan admin sekolah tertulis, versi backend dan kedua aplikasi cocok, kapasitas disetujui, backup/restore dan alarm terbukti, serta seluruh dependency produksi siap. Release/deploy/publikasi adalah tindakan tahap eksekusi dan memerlukan permintaan serta target produksi yang jelas.

| Keputusan | Hasil saat ini | Pengambil keputusan / waktu | Alasan/bukti |
| --- | --- | --- | --- |
| Hasil checklist QA | BELUM DIJALANKAN | NOT ASSIGNED | Urutan yang ditetapkan pengguna adalah testing final paling akhir; baris checklist tetap NOT RUN. |
| Go/no-go release | **NO-GO — pending** | NOT ASSIGNED | Kapasitas belum diukur, penerimaan sekolah belum dilakukan, versi release/environment belum diberikan, dan dependency produksi belum tersedia. M-03/M-06/I-01/I-03 juga memiliki pemeriksaan perangkat, Firebase, restore/staging yang masih tertunda. |
| Otorisasi deploy/publikasi | NOT AUTHORIZED | NOT ASSIGNED | Tidak ada tujuan deployment atau permintaan eksekusi produksi pada tahap persiapan ini. |

Status task/milestone proyek tidak diubah oleh checklist ini. Status aktual tetap di [PROGRESS.md](../civora/PROGRESS.md) dan indeks pada [TASKS.md](../civora/TASKS.md); dokumen ini bukan bukti kelulusan I-04.
