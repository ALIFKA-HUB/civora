# Goals, kriteria selesai, dan verifikasi Civora

Tanggal: 12 Oktober 2026. Acuan: [rancangan](README.md), [tugas](TASKS.md), dan [skenario penerimaan](08-verifikasi-dan-operasi.md).

Pengguna mengizinkan tahap implementasi dan memilih subagent dengan review per tugas. Satu implementer mengerjakan satu tugas; reviewer lain memeriksa kesesuaian rancangan serta kualitasnya sebelum pekerjaan lanjut. Kriteria ini mengatur bukti yang diperlukan, bukan menyatakan aplikasi sudah selesai.

Preferensi model pengguna (13 Oktober 2026): setiap subagent baru, termasuk implementer, reviewer, dan re-reviewer, memakai GPT-6 Luna (`gpt-6-luna`) dengan reasoning effort high. Orchestrator yang diminta adalah GPT-6.1 (`gpt-6.1-sol`) dengan effort medium; pemilihan model chat utama dilakukan melalui pengaturan chat, bukan tool spawn. Subagent yang sudah berjalan sebelum instruksi ini menyelesaikan tugasnya tanpa pengulangan pekerjaan. Preferensi pengguna ini mengatasi pilihan model bawaan skill.

## Goal utama

Sekolah dapat mengelola data melalui website admin; guru memilih siswa melalui QR/manual dan mencatat poin; siswa melihat catatan serta mengajukan keberatan. Seluruh platform menggunakan identitas, semester, nilai efektif, dan aturan akses yang sama. Prestasi dan pelanggaran tetap terpisah, dan perubahan dapat ditelusuri.

## Tambahan goal visual

Konsep visual yang disetujui dicatat pada [paket redesign](redesign/README.md). RD-01–RD-04 memiliki Definition of Done dan gate review tersendiri pada [goals redesign](redesign/verification-goals.md). Task tersebut tidak mengubah hitungan 26 task produk, milestone, atau status acceptance kamera, push, restore, dan kapasitas. Konsep, build, serta test widget tidak menggantikan penerimaan produk yang sudah ditetapkan di bawah.

## Milestone dan kriteria kelulusan

| Goal | Tugas | Lulus ketika | Bukti wajib |
| --- | --- | --- | --- |
| G0: Fondasi dapat dijalankan | B-01 | Laravel berjalan; health valid; migration dan constraint bekerja di PostgreSQL; prosedur setup terulang tanpa secret di repo | Versi runtime, test constraint, migration ulang pada DB test, health response, review B-01 |
| G1: Domain/backend benar | B-02 sampai B-11, R1 | Login/role/aktivasi, akademik, katalog, QR/manual, catatan, koreksi, ambang, import, laporan, dan notifikasi memenuhi kontrak | Backend test suite lulus, concurrency/retry/access tests lulus, seluruh review tugas disetujui |
| G2: Admin dapat menyelesaikan pekerjaan | A-01 sampai A-05, R2 | Admin dapat mengelola akun/data/semester, memproses pemeriksaan, mengatur ambang, dan mengunduh laporan melalui browser | Feature/Livewire tests, satu alur browser lengkap, QA keyboard/tema, review setiap tugas |
| G3: Dua aplikasi Android berfungsi | M-01 sampai M-06, R3 | Siswa dan guru punya aplikasi terpisah; seluruh alur terhubung API nyata dan tetap jelas saat gagal | Analyze/test lulus, dua APK terbuild, integration test serta kamera pada perangkat, review setiap tugas |
| G4: Integrasi konsisten | I-01/I-02 | Alur tiga platform terbukti; notifikasi dan total sesuai; XLSX/PDF sama dengan laporan layar | End-to-end, push nyata jika layanan tersedia, perbandingan ekspor, gambar PDF QA, review tugas |
| G5: Siap dipakai sekolah | I-03/I-04, R4 | Backup/restore, worker/scheduler, secret, TLS, distribusi aplikasi, dan kapasitas memenuhi kebutuhan sekolah | Restore terukur, checklist produksi, hasil kapasitas, penerimaan sekolah, review akhir keseluruhan |

G0 adalah checkpoint awal, bukan pengganti G1. Build APK tidak membuktikan alur kamera/push. Backend test tidak membuktikan tampilan admin/mobile. Kesiapan produksi tidak boleh disimpulkan hanya dari aplikasi berjalan lokal.

## Status tugas

- Belum mulai: belum ada implementasi tugas.
- Dikerjakan: implementer sedang bekerja; belum boleh dihitung selesai.
- Menunggu review: implementer memberi commit dan bukti uji; reviewer belum menyetujui.
- Perlu perbaikan: review menemukan kekurangan yang menghalangi kelulusan.
- Lulus: kriteria tugas terpenuhi, uji relevan lulus, review rancangan dan kualitas disetujui.
- Menunggu dependensi: ada kebutuhan konkret seperti credential FCM/perangkat/server; catat kebutuhan, dampaknya, dan pekerjaan yang masih dapat dilanjutkan.

Hanya status Lulus yang boleh mencentang tugas/milestone. Kebutuhan eksternal yang belum tersedia tidak diubah menjadi lulus melalui mock. Mock FCM dapat menguji logika worker, tetapi tidak menggantikan push nyata pada G4.

## Definition of Done setiap tugas

- [ ] Perilaku memenuhi brief dan dokumen rancangan yang terkait.
- [ ] Test domain/akses/kegagalan ditulis dahulu; kegagalan awal terlihat dan disebabkan fitur yang belum ada.
- [ ] Implementasi minimum membuat test tersebut lulus; test relevan dan suite tersedia dijalankan setelah perubahan final.
- [ ] Perintah, exit code, jumlah test/failure, dan cuplikan hasil tersedia dalam laporan.
- [ ] Implementer membaca diff sendiri dan mencatat deviasi/kekhawatiran.
- [ ] Reviewer terpisah memeriksa diff: kesesuaian spec dan kualitas sama-sama disetujui.
- [ ] Temuan critical/important diperbaiki dan diperiksa kembali sebelum lanjut; catatan minor tersimpan untuk review akhir.
- [ ] Tidak ada password/token/QR mentah/data siswa nyata dalam commit atau bukti publik.
- [ ] Commit tugas, status, dependensi, dan keputusan turunan dicatat dalam ledger/progress.

Untuk scaffolding/configuration, verifikasi berupa build/setup/health/migration yang relevan. Tidak menambah test yang hanya menyalin bentuk file. Fitur bisnis tetap memerlukan test perilaku.

## Kasus verifikasi yang tidak boleh dilewati

| Kelompok | Kriteria khusus |
| --- | --- |
| Akses | Siswa ditolak dari data/bukti siswa lain dan fitur guru/admin; guru tidak melihat password pribadi; penonaktifan menolak sesi lama |
| QR | Expired tepat pada batas 20 detik; satu penggunaan atomik; retry scan identik tidak membuat selection baru; QR baru mencabut lama |
| Simpan | Double tap/timeout setelah commit menghasilkan satu record; kunci sama dengan payload berbeda konflik; selection expired tidak diterima |
| Nilai | Guru tidak menentukan points; katalog direvisi tidak mengubah snapshot lama; prestasi tidak mengurangi pelanggaran |
| Pemeriksaan | Pending tidak mengubah total; approval atomik; keputusan basi konflik; cancellation tetap menyimpan audit |
| Akademik | Semester baru tidak menghapus data; laporan kelas memakai penempatan historis; lulus/pindah menonaktifkan akun |
| Import | Identifier nol awal dipertahankan; duplikat/referensi salah ditolak; batch commit semua atau tidak sama sekali |
| Berkas/laporan | Berkas privat; format/ukuran benar; teks tidak menjadi formula; total dan filter Excel/PDF konsisten |
| Notifikasi | Retry tidak menggandakan pesan; FCM gagal tidak membatalkan poin; detail hanya setelah login |
| UI | Terang/gelap, teks besar, fokus keyboard, denied permission, dan jaringan gagal tetap dapat dipahami |
| Operasi | Backup mencakup DB + bukti + akses kunci; restore diuji; cleanup tidak menghapus resource yang masih direferensikan |

Kode skenario rinci tetap merujuk ACC/QR/REC/REV/SEM/IMP/REP/NOT/UI/OPS pada dokumen 08, bukan membuat daftar aturan berbeda.

## Perintah verifikasi saat proyek tersedia

| Bagian | Perintah/kegiatan | Bukti kelulusan |
| --- | --- | --- |
| Backend | `php artisan test` dari backend | Exit 0, tidak ada test gagal; catat jumlah test/assertion |
| Style PHP | `vendor/bin/pint --test` dari backend, memakai wrapper Windows yang tersedia | Exit 0, tidak ada file perlu perbaikan |
| Migration | fresh migration pada database test terisolasi, lalu migration/rollback yang ditentukan tugas | Exit 0 dan constraint kembali benar; tidak dijalankan pada DB pengguna |
| Admin assets | `npm run build` dari backend | Exit 0, aset terbangun |
| Admin browser | Runner yang dipilih dan dicatat pada docs/development.md | Alur admin lengkap lulus; jika runner belum tersedia, status belum terverifikasi |
| Flutter | `flutter analyze` dan `flutter test` di kedua aplikasi/paket terkait | Tidak ada error analysis dan test gagal |
| Android | `flutter build apk --debug` di dua app | Dua build sukses dengan identitas app berbeda |
| Integration mobile | `flutter test integration_test` dengan API staging dan target perangkat yang dicatat | Alur nyata lulus; kamera/push diuji pada perangkat bila diperlukan |
| Ekspor | Pemeriksaan data XLSX dan render PDF halaman | Nilai/filter/identifier sama, tidak ada konten terpotong atau formula aktif |
| Restore | Restore PostgreSQL dan bukti ke lingkungan terisolasi | Data/bukti/audit terbaca; waktu restore dan umur backup tercatat |

Perintah disesuaikan hanya untuk executable lokal yang benar-benar dipakai; simpan perintah aktual lengkap dalam laporan. "Belum bisa menjalankan" adalah batasan verifikasi, bukan hasil pass.

## Format bukti dan pelaporan

Setiap laporan tugas memuat ID tugas, base/head commit, file yang berubah, RED/GREEN bila relevan, command/cwd/exit code, hasil suite, review, temuan/perbaikan, dan batasan. Laporan serta diff reviewer disimpan dalam workspace ledger lokal; ringkasan kemajuan dicatat di [PROGRESS.md](PROGRESS.md).

Persentase fitur tidak diperkirakan dari jumlah baris kode. Jika dibutuhkan, laporkan jumlah tugas Lulus dari 26 dan milestone mana yang belum lulus; ukuran tugas berbeda sehingga angka itu bukan estimasi waktu.

## Kriteria berhenti atau release ditunda

Pekerjaan lokal yang masih bermakna tetap dilanjutkan. Release ditunda jika akses lintas role bocor, test konsistensi gagal, review blocking belum selesai, backup/restore belum terbukti, atau dependency produksi belum tersedia. Perubahan destruktif terhadap data yang sudah ada, publikasi, dan perubahan layanan eksternal memerlukan konteks serta otorisasi yang sesuai; izin membuat aplikasi tidak menggantikan tujuan deployment yang belum ditentukan.

## Acuan kapasitas sekolah

Pengguna menetapkan sampai 3.000 siswa dan 200 guru sebagai skala data acuan untuk I-04. Ini bukan angka pengguna concurrent maupun jaminan kapasitas server produksi. Laporan uji harus mencatat skenario concurrent, hardware/runtime, latensi/error, dan batasan; kesiapan produksi tetap menunggu infrastruktur serta penerimaan sekolah.
