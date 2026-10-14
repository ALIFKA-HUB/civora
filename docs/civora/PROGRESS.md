# Kemajuan implementasi Civora

Mulai: 12 Oktober 2026. Cara kerja: subagent implementer dan reviewer terpisah per tugas.

## Status awal

- Rancangan dan 26 tugas tersedia serta telah disetujui.
- Kriteria goal dan verifikasi ditetapkan di [GOALS.md](GOALS.md).
- PHP 8.4.0, Composer 2.8.11, Node 26.3.0, Git 2.47.0, Flutter 3.41.9, dan Dart 3.11.5 ditemukan melalui perintah versi.
- Instalasi PostgreSQL 17/18 ditemukan; tidak ada listener pada port 5432 yang ditemukan pada pemeriksaan awal.
- PHP CLI saat ini tidak memuat pdo_pgsql. Runtime PostgreSQL dan driver PHP harus disiapkan sebelum test database dinyatakan valid.
- B-01 sampai B-11, A-01 sampai A-05, dan M-01/M-02/M-04/M-05 Lulus setelah implementasi dan review independen. 20 dari 26 tugas Lulus; website admin dan aplikasi mobile belum selesai.

## Milestone

| Goal | Status | Bukti |
| --- | --- | --- |
| G0: Fondasi | Lulus | Commit 3bd1c1a; 84 test/260 assertion, Pint, migration, health HTTP, review bersih |
| G1: Backend | Lulus | B-11 fix 823fbd9; test 6/43 assertion lulus, re-review menyetujui; suite sebelum fix 254/1.007 |
| G2: Admin | Lulus | A-01 sampai A-05, alur browser terpadu 1/10, suite 309/1.337 sebelum fix terakhir; test fix dan re-review lulus |
| G3: Mobile | Menunggu Android: M-03/M-06 | Review kode lokal bersih; QR/kamera, native cold-start, dan UI integration belum diverifikasi |
| G4: Integrasi | Firebase testing terpasang lokal; uji perangkat/push pending | API/outbox dapat diuji lokal; scan dua target, OAuth dan push nyata masih pending |
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

Reviewer B-02 menyetujui kesesuaian rancangan dan kualitas tanpa temuan blocking/minor. B-02 Lulus; langkah berikutnya B-03 untuk semester, penempatan, perpindahan, serta status pendidikan siswa. G1 belum lulus sampai seluruh backend selesai.

### 13 Oktober 2026

Pengguna menetapkan GPT-6 Luna dengan effort high untuk semua subagent baru, termasuk reviewer, dan menginginkan GPT-6.1 dengan effort medium sebagai orchestrator. Preferensi tersimpan pada GOALS.md; pemilihan model chat utama dilakukan melalui pengaturan chat.

#### B-03: implementasi selesai, menunggu review independen

Commit 6d48372 menyediakan pembukaan semester, penempatan awal, perpindahan kelas, perubahan status pindah/lulus, serta query penempatan dengan kalender sekolah. Laporan implementer: suite backend 155 test/522 assertion lulus, Pint dan diff check lulus. Reviewer GPT-6 Luna high memeriksa kesesuaian rancangan dan kualitas; B-03 belum dicentang.

Penempatan awal EnrollStudent melengkapi kebutuhan persiapan semester draft untuk admin/import. Penempatan masa depan siswa yang keluar dipertahankan tanpa membuat rentang nol/negatif; status nonaktif mencegah pemakaian. Test perlombaan perpindahan dengan pencatatan tetap menjadi kewajiban B-07 dan akses guru ke arsip siswa lulus menjadi B-08.

Database lokal proyek sempat tidak menerima koneksi. Cluster milik workspace dinyalakan kembali, lalu test terfokus dan suite final lulus; penyebab berhenti belum diketahui. Data developer dan instalasi/database di luar proyek tidak direset.

Reviewer GPT-6 Luna high menyetujui B-03 tanpa temuan critical/important/minor. B-03 Lulus; pengerjaan dilanjutkan ke katalog poin berversi B-04. Total milestone backend belum dinyatakan selesai.

#### B-04: katalog Lulus

Commit 0c1c45f menyediakan katalog aktif guru dan mutasi katalog admin dengan versi append-only. Nilai positif wajib; kategori yang sudah direferensikan catatan tidak dapat diubah. Test terfokus 13/43 assertion dan suite backend 168/565 assertion lulus, Pint/diff check lulus. Reviewer GPT-6 Luna high menyetujui tanpa temuan. B-07 wajib mengunci jenis poin dan memeriksa ulang status aktif/versi terbaru saat menyimpan. Langkah berikutnya B-05 untuk total terpisah, ambang, serta tindak lanjut.

#### B-05: total dan ambang Lulus

Commit 932d6bd menyediakan total efektif per siswa/semester, evaluator ambang, kebijakan berversi, dan pencatatan tindak lanjut. Revisi terakhir aktif menjadi sumber total; pembatalan dikecualikan dan tindak lanjut tidak mengurangi poin. Test terfokus 10/27 assertion dan suite 178/592 assertion lulus; Pint/diff check lulus. Reviewer GPT-6 Luna high menyetujui tanpa critical/important. Minor yang disimpan untuk review akhir: cakupan langsung cabang tanpa kebijakan yang membersihkan status peringatan. B-07/B-08 wajib memanggil evaluator setelah revisi di transaksi dan lock yang sama.

#### B-06: QR dan pemilihan Lulus

Commit cb1b0ea menyediakan QR hash-only 20 detik, selection 5 menit, scan atomik, retry aman, pencarian/profil siswa, dan pemilihan manual. Test terfokus 11/60 assertion dan suite 189/652 assertion lulus; Pint/diff check lulus. Reviewer GPT-6 Luna high menyetujui kontrak endpoint tanpa critical/important. Minor untuk review akhir: tambahkan filter tahun ajaran kelas pada pencarian agar sama dengan validasi selection/profil. Pencabutan/expiry sesi penerbit menolak redeem; retry setelah expiry/rotasi QR hanya mengembalikan selection lama jika sesi, selection, semester, dan penempatan masih sah.

#### B-07: perlu perbaikan setelah review

Commit 2ebd141 melaporkan pencatatan atomik, idempotensi, foto privat, dan race perpindahan/ambang; suite 199/702 assertion lulus. Reviewer menemukan download foto memakai pemeriksaan sendiri yang tidak mengikuti AttachmentPolicy: admin/guru yang berhak dapat ditolak, sementara akun pending belum dibatasi dengan benar. Temuan diverifikasi pada controller/policy dan dikembalikan ke implementer untuk regresi/perbaikan. B-07 belum lulus. Minor untuk review akhir: samakan format idempotency key POST dengan route lookup GET. Cleanup bukti staged yang belum dipakai tetap tugas B-11.

Fix ddab9ef memakai AttachmentPolicy dan guard akun aktif sebelum membuka file. Regresi awal gagal pada akses admin/guru serta akun pending; setelah fix PrivateEvidenceTest 5/17 assertion, Pint, dan diff check lulus. Re-review GPT-6 Luna high menyatakan temuan terselesaikan tanpa breakage baru. B-07 Lulus; suite 199/702 adalah bukti sebelum fix, verifikasi kode fix memakai test terfokus tersebut. Langkah berikutnya B-08; minor format key tetap disimpan untuk review akhir.

#### B-08: perlu perbaikan setelah review

Commit 6df3fd8 melaporkan riwayat, ringkasan, profil/metadata, keberatan, dan keputusan revisi dengan suite 217/771 assertion lulus. Reviewer menemukan perubahan bukti hanya memeriksa ID ada, sehingga lampiran pengguna lain dapat ditautkan. Test keputusan dua admin juga masih berurutan. Perbaikan dikembalikan ke implementer: validasi kepemilikan/record bukti saat usulan dan saat penerapan, serta uji paralel PostgreSQL. B-08 belum lulus; filter tahun ajaran pencarian B-06 telah diperbaiki pada perubahan metadata, tetap diverifikasi dalam review ini.

Fix c9391c1 memvalidasi lampiran milik guru pencatat dan menolak penautan lintas catatan, pada usulan maupun penerapan terkunci; test 20/79 assertion lulus. Re-review menyetujui lampiran, tetapi meminta bukti kedua proses siap sebelum barrier dilepas. Fix c01e379 memenuhi barrier kesiapan; test PostgreSQL 1/9 assertion, Pint/diff check lulus, re-review kedua menyetujui tanpa breakage. B-08 Lulus. Suite 217/771 adalah bukti sebelum fix; kode terakhir diverifikasi melalui cakupan terfokus tersebut. Minor pencarian tahun ajaran B-06 selesai pada perubahan metadata; minor lain tetap untuk review akhir. Langkah berikutnya import B-09.

Pengguna mengonfirmasi memiliki HP Android, tetapi belum memiliki Firebase. Pengerjaan lokal dan gateway/test notifikasi dapat dilanjutkan. Push nyata pada I-01/G4 belum dapat dinyatakan lulus sebelum project/config dan uji perangkat tersedia. Ketersediaan HP tidak menyatakan perangkat sudah tersambung atau mengizinkan publikasi/deployment.

#### B-09: perlu perbaikan setelah review

Commit 3b0bbd9 menyediakan import terbatas CSV/XLSX, pratinjau privat, commit atomik, dan dependency PhpSpreadsheet 5.10. Test terfokus 12/47 assertion dan suite 236/849 assertion lulus. Review meminta penolakan preview tidak terkonfirmasi pada batas 7 hari, serta validasi ulang tanggal semester/tahun ajaran dengan error yang membantu admin. Perbaikan dikembalikan ke implementer; cleanup berkas dan parsed rows terjadwal tetap tugas B-11. B-09 belum lulus.

Fix d65fcc8 menolak preview pada batas tepat 7 hari dan memeriksa ulang semester/tahun ajaran serta waktu penempatan sebelum membuat akun, dengan error per baris. Test 16/73 assertion, Pint/diff check lulus; re-review menyetujui tanpa breakage. B-09 Lulus. Retry batch terkonfirmasi tetap tidak membuat akun baru; cleanup terjadwal B-11 menghapus sumber/preview privat sesuai lifecycle. Suite 236/849 adalah bukti sebelum fix; kode fix memakai test terfokus. Langkah berikutnya B-10 untuk laporan dan ekspor.

#### B-10: perlu perbaikan setelah review

Commit 3fce9d9 menyediakan laporan historis dan ekspor privat XLSX/PDF. Test terfokus 5/50 assertion dan suite 245/925 assertion lulus; Pint/diff check lulus. Review menemukan job ekspor dapat tertinggal pada processing setelah worker terputus, serta persiapan filesystem berada di luar penanganan kegagalan. Implementer memperbaiki pemulihan dengan kepemilikan setiap percobaan agar worker lama tidak menimpa hasil baru. B-10 belum lulus. Pemeriksaan visual PDF panjang tetap I-02.

Fix f4340ee menambahkan claim/lease, berkas per percobaan, publikasi terkunci, dan penanganan kegagalan yang tidak membatalkan hasil worker baru. PointReportTest 9/74 assertion, Pint/diff check lulus; re-review menyetujui tanpa critical/important. B-10 Lulus. Minor tersimpan untuk review akhir: crash setelah pemindahan berkas sebelum commit dapat meninggalkan berkas tanpa referensi. Suite 245/925 adalah bukti sebelum fix; pemeriksaan visual PDF panjang tetap I-02. Langkah berikutnya B-11 notifikasi dan cleanup.

#### B-11: menunggu review

Commit c07adbc menyediakan outbox, notifikasi milik sendiri, token perangkat terikat sesi, gateway FCM yang dapat dikonfigurasi, dan cleanup privat. Test terfokus 5/58 assertion dan suite backend 254/1.007 assertion lulus; Pint/diff check lulus. Reviewer terpisah memeriksa retry, akses/sesi perangkat, serta retensi lampiran dan ekspor. Firebase belum tersedia sehingga push nyata belum terbukti. Crash setelah FCM menerima pesan sebelum outbox ditandai dapat menyebabkan push ulang; notifikasi in-app dideduplikasi. B-11 belum lulus.

Review B-11 meminta perbaikan keputusan koreksi guru yang ditolak agar siswa pemilik catatan ikut diberi tahu, serta pembedaan token UNREGISTERED dari 404 kesalahan konfigurasi Firebase. Implementer menambahkan regresi untuk dua kasus tersebut. Minor cakupan batas retry dan retensi lampiran revisi dicatat untuk review akhir. Cleanup berkas ekspor tanpa referensi telah diperiksa dan menangani minor B-10; push nyata tetap dependensi I-01.

Fix 823fbd9 menambahkan penerima siswa pada keputusan koreksi guru dan hanya mencabut perangkat untuk detail FCM UNREGISTERED bertipe resmi. Test terfokus 6/43 assertion, Pint/diff check lulus; re-review menyetujui tanpa breakage. B-11 dan G1/R1 Lulus. Suite 254/1.007 adalah bukti sebelum fix; kode terakhir diverifikasi dengan test terfokus. Firebase/OAuth/perangkat nyata tetap I-01/G4, minor test tetap review akhir. Pengerjaan berlanjut ke A-01 untuk session, layout, tema, dan dashboard admin.

#### Repository GitHub dan checkpoint admin

Repository public tersedia di [ALIFKA-HUB/civora](https://github.com/ALIFKA-HUB/civora). Main berisi dasar proyek; pengerjaan dan commit saat ini berada pada branch codex/civora-implementation yang terhubung ke origin. Pemeriksaan historis sebelum push tidak menemukan pola private key/credential umum atau berkas .env/runtime privat yang terlacak.

A-01 diimplementasikan pada 6b535b0 dan menunggu review independen: AdminSessionTest 7/34 assertion, suite backend 264/1.052 assertion, browser Dusk 1/36 assertion, build web, Pint/diff check lulus. Browser memeriksa tema terang/gelap/sistem, kontras, keyboard dan viewport 320/768/1024/1440. Belum mencentang A-01/G2. Kelanjutan implementasi ditahan sesuai permintaan pengguna untuk menyiapkan GitHub terlebih dahulu.

Pengguna meminta commit/push setiap tugas atau perubahan bermakna dan pembaruan issue/PR dengan hasil yang nyata. GitHub memetakan author commit saat ini ke ALIFKA-HUB. Commit branch kerja menunggu integrasi ke main untuk kontribusi commit; PR/issue dapat dihitung sesuai kriteria GitHub. Aturan kerja tersimpan di AGENTS.md; kelulusan review tetap wajib.

#### A-01: perlu perbaikan setelah review

Review independen menemukan guard idle/role admin belum dipersistenkan pada update Livewire, serta drawer mobile belum memiliki fokus modal dan pemulihan fokus. Implementer memperbaiki kedua kasus dengan regresi HTTP update dan browser. Panduan Dusk dari checkout baru juga perlu tersedia pada dokumentasi publik. Suite 264/1.052 dan Dusk 1/36 adalah bukti sebelum fix; A-01 belum lulus.

Fix a9cfc7d menegakkan empat guard admin pada update Livewire dan membuat drawer modal dengan pembatasan serta pemulihan fokus. AdminSessionTest 11/64 assertion, Dusk 1/44 assertion, build/Pint/diff check lulus; re-review menyetujui tiga temuan tanpa critical/important baru. A-01 Lulus. Suite 264/1.052 adalah bukti sebelum fix; verifikasi kode fix memakai test terfokus dan browser. Minor nama landmark desktop dan cakupan kontras tambahan dicatat untuk review akhir. Pengerjaan berikutnya A-02 pengelolaan siswa, guru, akademik, import, dan bantuan akun.

#### A-02: implementasi tersedia, menunggu review

Commit 032d081 telah di-push ke branch kerja. Pengelolaan siswa/guru, akademik, bantuan akun sementara, template CSV/XLSX, dan preview/konfirmasi impor tersedia. AdminDataManagementTest: 14 test/73 assertion; suite backend final: 282 test/1.155 assertion; browser Dusk impor sampai aktivasi: 1 test/10 assertion. Semua command tersebut, Pint, build Vite, dan diff check keluar dengan exit 0.

Integrasi memperbaiki representasi null/daftar kosong pada error_summary impor B-09. Runtime server lokal memakai folder upload sementara milik proyek melalui ops/dev/serve.ps1. Browser QA memakai session file khusus pengujian; hasil ini tidak membuktikan pencabutan session database produksi. Reviewer independen GPT-6 Luna high sedang memeriksa perubahan; A-02 belum dicentang.


Review A-02 menemukan pencarian OR yang dapat melewati filter status. Regresi mereproduksi kegagalan pada daftar siswa dan guru; fix b50e7ee mengelompokkan alternatif pencarian. Test terfokus setelah fix: 16 test/83 assertion, Pint/diff check exit 0. Re-review menyetujui satu temuan tanpa masalah baru. A-02 Lulus; 13/26 tugas lulus. Suite 282/1.155 dan browser 1/10 adalah bukti sebelum fix. Minor tautan import guru yang tidak menerapkan pilihan jenis disimpan untuk review akhir. Berikutnya A-03 katalog, ambang, dan tindak lanjut.

#### A-03: katalog, ambang, dan tindak lanjut Lulus

Implementasi f908a80 menyediakan tiga layar dengan versi katalog/kebijakan, kontrol berlabel, dan tema terang/gelap. Review meminta penolakan peringatan nonaktif/kebijakan lama pada action tindak lanjut, serta konversi datetime-local dari zona waktu sekolah ke UTC. Fix a4c6604 menegakkan validasi setelah lock semester dan mengonversi waktu sebelum penyimpanan.

Setelah fix: AdminPointRulesTest 6 test/46 assertion, PointTotalsAndThresholdTest 10/27, suite backend 290/1.211, Pint/diff check exit 0. Re-review menyetujui dua temuan tanpa masalah baru dan memeriksa kesamaan urutan lock. Browser 1/18 assertion serta build Vite lulus sebelum fix; markup tidak berubah pada fix. A-03 Lulus; 14/26 tugas lulus. Berikutnya A-04 catatan dan pemeriksaan.

#### A-04: catatan dan pemeriksaan Lulus

Implementasi b9ce479 menyediakan catatan, antrean/detail pemeriksaan, keputusan dengan revisi terkunci, koreksi langsung, serta route foto privat yang memakai AttachmentPolicy dan session admin. Test terfokus 10/63 assertion, suite 300/1.274, browser 1/15 (foto berhasil dimuat pada dua tema), Pint/build/diff check lulus. Suite/browser mendahului tambahan tautan muat ulang konflik; test terfokus lulus setelahnya.

Review meminta preview penolakan menampilkan catatan yang tetap sama, bukan perubahan usulan. Fix aa23881 mereproduksi masalah lalu meluluskan 11 test/73 assertion, Pint/build/diff check (exit 0). Re-review menyetujui tanpa masalah baru. A-04 Lulus; 15/26 tugas lulus. Suite dan browser tersebut tidak diulang setelah fix preview; langkah berikutnya A-05 laporan, pengaturan, dan audit.

#### A-05 dan G2/R2 Lulus

Commit 48fa68d menyediakan laporan/unduhan privat, pengaturan sekolah, manajemen admin, dan audit. Alur browser impor → katalog → semester → catatan API → pemeriksaan → laporan lulus 1 test/10 assertion sebelum fix pengaturan pertama kali; test terfokus sebelumnya 8/53. Pemulihan setelah interupsi meluluskan suite final 309 test/1.337 assertion, Pint/build/diff check exit 0. Percobaan tertahan saat cluster PostgreSQL proyek berhenti dihentikan tanpa mengklaim hasil test; cluster milik proyek dinyalakan kembali, lalu suite tersebut lulus.

Review meminta alias API key disamarkan dan zona waktu tersimpan dipakai pada tindak lanjut. Fix 3987438: AdminReportAccessTest 8/58 dan AdminPointRulesTest 7/51, Pint/diff exit 0; re-review menyetujui dua temuan tanpa masalah baru. Reviewer juga mengulang test terfokus tersebut, tidak suite penuh/browser. A-05 serta G2/R2 Lulus; 16/26 tugas lulus. Suite 309/1.337 dan browser 1/10 mendahului fix terakhir; kualitas ekspor panjang/visual tetap I-02, push nyata tetap I-01. Berikutnya M-01 fondasi dua aplikasi Flutter.

#### M-01: fondasi mobile Lulus

Commit 7872d45 menyediakan aplikasi Android siswa/guru terpisah, paket core/UI, DTO UTC, session dasar, client API, serta tema Noto Sans terang/gelap/sistem. Test core 6, UI 1, dan smoke test aplikasi masing-masing 1 serta analisis empat target lulus. Kedua APK debug terbuild; ID/label/izin internet diverifikasi melalui aapt. Firebase dan uji perangkat fisik belum dibuktikan.

Review menemukan URL API per lingkungan belum tersambung ke client shell. Fix ba4a040 membuat client/session pada root aplikasi, menjaga instance saat rebuild, dan menutup transport saat disposal. Test aplikasi masing-masing 3, core 6, analisis kedua aplikasi, serta kedua build APK exit 0; re-review menyetujui tanpa temuan baru. APK lokal final berukuran 160.055.737 dan 160.055.731 byte. M-01 Lulus; 17/26 tugas lulus, lanjut M-02 autentikasi. Shell ini belum berarti seluruh fitur siswa/guru selesai. Respons selection memakai student_id tanpa profil; pengayaan identitas menjadi M-04. Zona waktu tersimpan belum tersedia pada metadata API; kebutuhan tampilan tanggal ditangani saat M-03.

#### M-02: autentikasi mobile Lulus

Commit 5575a56 menyediakan login sesuai role, aktivasi, profil/ganti password, secure token storage, restore melalui me, serta logout. Test lokal core 12, siswa 5, guru 4, analisis empat target, dan kedua build APK lulus. API test memakai respons terkontrol; integrasi Laravel serta secure storage perangkat fisik belum dibuktikan.

Review meminta logout pada aktivasi, penutupan route profil setelah session berakhir, dan perlindungan token baru dari 401 request lama. Fix 86c6d33 meluluskan core 14, test auth siswa 5/guru 4, analisis core/dua app, kedua build APK, dan diff check exit 0. Regresi stale-401 memakai mutasi sementara perilaku expiry lama lalu mengembalikan guard final; bukan hasil checkout baseline. Re-review menyetujui tiga temuan tanpa masalah baru. Minor pesan storage yang dianggap gangguan jaringan dicatat untuk review akhir. M-02 Lulus; 18/26 tugas lulus, lanjut fitur siswa M-03.

#### M-03: review kode lokal disetujui, menunggu HP

Commit c9cb843 menyediakan ringkasan siswa, QR server-relative 20 detik, riwayat/detail privat, dan keberatan. Test backend 311/1.354 assertion, core 16, siswa 17, guru 7, analisis tiga target, kedua APK, dan diff check exit 0. Review independen menyetujui kode tanpa temuan; pemeriksaan bentuk record/revisi dan zona waktu tersimpan juga dilakukan.

M-03 belum Lulus: QR terang/gelap hanya dibuktikan pada widget, ADB tidak menemukan perangkat tersambung, dan belum ada AVD. Pengguna menyatakan bisa menyambungkan HP tetapi perlu waktu; uji fisik tetap menunggu. Jumlah tugas Lulus tetap 18/26. M-04 dapat dikerjakan sebagai pekerjaan lokal independen sesuai dependensinya M-02/B-06/B-07/B-08; status HP tidak diganti menjadi pass.

#### M-04: pencatatan guru Lulus

Commit cc15bb5 menyediakan scan/manual, profil/riwayat, draft foto privat, ringkasan, hasil, retry dengan payload/key tetap, dan koreksi. Verifikasi awal final: core 20, guru 18, siswa 17; analisis tiga target, dua APK, serta diff check exit 0. Multipart memakai library HTTP dan snapshot autentikasi sebelum persiapan async. Kamera/QR fisik belum dibuktikan.

Review meminta lock unknown tidak bisa dibatalkan dari form dan respons pencarian lama diabaikan. Fix 8a66e0b: guru 21 test, analisis/build/diff lulus. Re-review meminta respons selection terlambat juga memeriksa lock saat dipasang. Fix 40d3371: retry 7/guru penuh 22, analisis/build/diff exit 0; re-review kedua menyetujui tanpa masalah baru. Backend archive siswa lulus dan hak bukti dicocokkan pada source/test B-07/B-08 yang tidak berubah, bukan diklaim sebagai test API live baru. Minor test expiry disimpan untuk review akhir. M-04 Lulus; 19/26 tugas lulus. M-03 tetap menunggu HP; scan nyata dialokasikan M-06/I-01. Berikutnya M-05 notifikasi dan bantuan akun.

#### M-05: notifikasi dan bantuan akun Lulus lokal

Commit 67a9376 menyediakan notifikasi/read state, deep link terbatas dengan fetch server sesuai role, tema persisten, lifecycle push opsional, dan dialog bantuan password sementara. Field account_status/must_change_password ditambahkan secara terbatas pada profil siswa yang boleh dilihat guru. Test backend 312/1.364 assertion, siswa 21/guru 32/core 20/UI 1, analisis empat target, dua build APK tanpa konfigurasi Firebase, serta diff check exit 0. Mutasi sementara penghilangan flag profil membuktikan regresi, bukan checkout baseline.

Review meminta respons daftar notifikasi lama tidak mengganti state akun baru. Fix 185f98d: core 23, notifikasi masing-masing aplikasi 4, UI 1, analisis empat target, kedua build APK/diff exit 0; re-review menyetujui tanpa masalah baru. Suite backend sebelumnya tidak diulang karena fix hanya client. M-05 Lulus sesuai cakupan lokal; 20/26 tugas lulus. Native cold-start dan push Firebase nyata belum dibuktikan, tetap M-06/I-01. M-03 masih menunggu HP. Pengguna menetapkan skala data acuan 3.000 siswa dan 200 guru untuk I-04, tanpa mengasumsikan jumlah concurrent atau jaminan server produksi.

#### M-06: kode/HTTP lokal disetujui, Android pending

Commit 0c84646 menambahkan integration test UI, command fixture sintetis berpengaman, smoke HTTP nyata melalui ApiClient/SessionController, dan runbook mobile. Command integration Android dicoba tetapi exit 1 karena tidak ada perangkat/AVD; smoke HTTP lulus, kedua APK terbuild, minimum aktual API 24/target API 36 diverifikasi dengan aapt. Guard test 1/5, Pint/diff lulus. Test guard awal sempat mereset database disposable lewat RefreshDatabase; guard sekarang read-only, fixture baru dan smoke ulang diverifikasi, salinan credential sintetis lama dibersihkan dari path yang tepat. JSON credential lokal tetap diabaikan Git.

Review memperbaiki interaksi fixture: smoke tidak lagi membuat keberatan pada catatan yang akan dipakai test UI. Fix 9e301a1 memakai record QR buatan smoke; fix a28cf60 memeriksa ID target itu sebelum POST dan memastikan fixture UI tetap tanpa pending review. Smoke aktual/analyze/diff exit 0; re-review terakhir menyetujui tanpa temuan baru. Artefak review awal yang belum tersimpan diarsipkan dari verdict reviewer secara transparan. M-06 belum Lulus tanpa UI integration/perangkat; M-03 juga menunggu HP. Jumlah Lulus tetap 20/26. Pekerjaan lokal independen I-01 dilanjutkan, Firebase/staging/produksi belum diklaim tersedia.

#### Perbaikan cold-start notifikasi — Lulus lokal

Review branch menemukan pesan pembuka push hilang sebelum listener terpasang. Commit 27af005 memasang listener sebelum inisialisasi. Regresi notification_controller_test gagal sebelum fix (exit 1), kemudian 4/4 test lulus (exit 0); analyze core dan diff check exit 0. Reviewer terpisah menyetujui tanpa temuan; suite penuh, APK, dan push perangkat tidak diuji ulang. Pengguna memilih testing menyeluruh pada tahap akhir; cek terfokus tetap dilakukan per perubahan. Jumlah tugas utama tetap 20/26.


#### I-02: kesamaan ekspor dan layout — Lulus

Commit c73bd8a menambahkan fixture 121 baris terfilter, kelas historis, revisi/pembatalan, kontrol semester, NISN nol awal, dan teks mirip formula. ExportConsistencyTest 1/2.856 assertion exit 0; Pint dan diff check exit 0. PDF final 46 halaman dirender, halaman awal/detail/akhir diperiksa, header/nomor halaman dan pembungkusan teks terbaca. Review independen menyetujui spesifikasi/kualitas; perbandingan PDF dibatasi jumlah baris, total, teks terpilih dan inspeksi generator, bukan per-sel penuh seperti XLSX. Sampel privat ignored. I-02 Lulus; 21/26 tugas Lulus. Suite penuh dan testing perangkat tetap tahap akhir sesuai pengguna.


#### I-03: persiapan runbook — disetujui, latihan pending

Commit 3d185c6 menyediakan deployment, backup/restore, incident response, komentar konfigurasi tanpa nilai secret, dan pembaruan panduan developer. Review meminta path lampiran sesuai cwd serta identitas database dibuktikan sebelum diagnostik Artisan; fix 1470db3 menutup keduanya dan re-review menyetujui. Cek dokumentasi/tautan, nama konfigurasi, command Artisan yang tersedia, urutan/path restore, dan diff exit 0. Bash tidak tersedia sehingga parser shell tidak dijalankan. Tidak ada backup, restore, deploy, alarm, atau suite luas yang dijalankan. I-03 tetap pending latihan testing akhir dan staging/offsite/kepemilikan secret; jumlah Lulus tetap 21/26.

#### I-04: persiapan kapasitas dan penerimaan — disetujui, pengukuran pending

Commit 7d7a4eb menyiapkan checklist release dan rencana kapasitas 3.000 siswa/200 guru. Profil histori, concurrency, serta anggaran performa adalah asumsi/hipotesis atau belum disetujui, bukan hasil pengukuran. Review meminta fail-fast command PowerShell, server di terminal terpisah, serta jaringan dan URL build yang konsisten untuk emulator/HP. Fix 5abf2a dan 3c4548d menutup temuan; re-review akhir lulus. Cek tautan, parsing PowerShell, nama konfigurasi/command, simulasi exit 7, dan diff exit 0. Tidak menjalankan suite luas, beban, restore, perangkat, ADB, Firebase, atau deploy. I-04 tetap pending testing akhir/penerimaan sekolah/dependency produksi. Status 21/26 tugas Lulus; M-03, M-06, I-01, I-03, I-04 belum Lulus. Release tetap NO-GO.

#### Firebase testing — konfigurasi lokal disetujui

Pengguna membuat project civora-testing, mendaftarkan aplikasi siswa/guru, dan menyediakan konfigurasi Android serta service account dari Downloads. Commit 9ee6299 melindungi file konfigurasi/credential lewat Git ignore dan mencatat bukti build lokal. Konfigurasi Android dan private key backend disalin ke lokasi lokal ignored; file asal tidak diubah. Dua build APK debug exit 0; package dan resource project/app Firebase cocok. Konfigurasi Laravel development cocok; testing dan dusk.local tidak memakai FCM nyata, tanpa inherited override/config cache. Reviewer independen menyetujui tanpa temuan; diff check exit 0. OAuth, pengiriman push dan perangkat belum diuji. ADB devices exit 0 tanpa perangkat; pengguna memilih menyelesaikan konfigurasi lokal dahulu. Jumlah task Lulus tetap 21/26.

#### M-06: workflow Android nyata — lulus parsial, acceptance perangkat pending

HP Samsung SM-S711B Android 16/API 36 tersambung dengan izin debugging. Workflow siswa lulus 1/1 sebelum fix URI bersama. Pencarian guru mereproduksi HTTP 422 karena query filter null dikirim sebagai teks; regresi URI RED exit 1 lalu GREEN 13 test exit 0 setelah fix. Harness guru diperbaiki untuk menunggu dropdown dan memastikan tombol kirim terlihat setelah keyboard ditutup. Run Android guru terakhir lulus 1/1; test payload koreksi 1/1 dan dua analyze terfokus exit 0. Tidak ada bug payload koreksi produksi; dugaan 503 log server juga dibatalkan karena angka merupakan durasi request. Commit 67bb9dc disetujui review/re-review setelah nomor seri HP disamarkan dari dokumen publik dan commit lokal. Bukti: docs/qa/android-device.md. Kamera/QR hidup laptop, permission/lifecycle/accessibility dan push nyata masih pending; M-03/M-06/I-01 belum Lulus dan jumlah tetap 21/26.
