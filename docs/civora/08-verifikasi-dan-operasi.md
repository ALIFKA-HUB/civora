# Verifikasi dan operasi

[Kembali ke indeks](README.md)

Dokumen ini menentukan pemeriksaan yang harus dilakukan saat implementasi. Ia tidak menyatakan bahwa aplikasi telah diuji; saat rancangan dibuat belum ada kode aplikasi.

## Skenario penerimaan inti

| ID | Skenario | Hasil wajib |
| --- | --- | --- |
| ACC-01 | Siswa mencoba login di endpoint guru | Ditolak; tidak mendapat role guru |
| ACC-02 | Password sementara valid/expired/consumed | Aktivasi terbatas ketika valid; expired/consumed ditolak |
| ACC-03 | Guru membuka password pribadi siswa aktif | Tidak tersedia; percobaan endpoint tidak membocorkannya |
| ACC-04 | Admin reset/menonaktifkan akun | Token lama dan QR ditolak; audit tersedia |
| ACC-05 | Siswa menebak ID catatan/bukti siswa lain | Ditolak tanpa mengungkap data |
| QR-01 | QR pada detik sebelum/saat batas expired | Valid sebelum expires_at; ditolak ketika sudah expired menurut server |
| QR-02 | Dua guru scan token sama bersamaan | Tepat satu redeem berhasil |
| QR-03 | Retry scan dengan request_key sama | Mengembalikan selection yang sama, tidak menghasilkan selection kedua |
| QR-04 | QR baru dibuat/background/offline | QR lama dicabut atau tidak ditampilkan aktif; countdown mengikuti server |
| QR-05 | Siswa inactive/tanpa kelas/semester tertutup | QR/pencatatan ditolak dengan pesan yang tepat |
| REC-01 | QR dan manual memilih siswa sama | Formulir dan nilai sama; sumber pemilihan berbeda di audit |
| REC-02 | Guru mengirim nilai/siswa/semester palsu | Server memakai sumber yang sah atau menolak payload |
| REC-03 | Double tap atau timeout setelah commit | Tepat satu record; retry mengembalikan record yang sama |
| REC-04 | Kunci sama dengan payload berbeda | Konflik, tidak membuat record kedua |
| REC-05 | Katalog berubah setelah ringkasan dibuka | Guru diminta meninjau nilai baru |
| REC-06 | Kelas/semester berubah saat formulir terbuka | Tidak menyimpan pada penempatan lama; pilih ulang |
| REC-07 | Dua catatan paralel melewati ambang bersama | Total tepat dan ambang tidak terlewat |
| REV-01 | Keberatan siswa/koreksi guru pending | Catatan tetap masuk total; maksimal satu pending per record |
| REV-02 | Admin setuju koreksi/pembatalan | Revisi dan keputusan atomik; total serta status ambang berubah konsisten |
| REV-03 | Dua admin memutuskan versi sama | Keputusan kedua konflik; tidak menimpa diam-diam |
| REV-04 | Koreksi jenis atau nilai | Nilai berasal dari versi katalog yang dipilih; catatan awal tetap ada |
| REV-05 | Salah siswa lalu dibatalkan | Siswa asli tidak diganti; pencatatan pengganti memakai alur guru baru |
| SEM-01 | Pergantian semester | Total periode baru nol tanpa menghapus riwayat |
| SEM-02 | Pindah kelas di tengah semester | Rekap kelas lama dan baru sesuai waktu kejadian |
| SEM-03 | Siswa pindah/lulus | Akun nonaktif, riwayat/admin tetap tersedia |
| IMP-01 | Excel/CSV dengan NISN nol awal | Identifier tetap teks dan tidak berubah |
| IMP-02 | Duplikat/kelas salah/file tidak sah | Pratinjau kesalahan; konfirmasi tidak tersedia |
| IMP-03 | Referensi berubah setelah preview | Validasi ulang gagal tanpa import parsial |
| IMP-04 | Batch valid/gagal di tengah commit | Semua baris tersimpan atau seluruh batch dibatalkan |
| REP-01 | Layar, Excel, dan PDF dengan filter sama | Total, status, semester, dan penempatan historis konsisten |
| REP-02 | Catatan dibatalkan/formula dalam teks | Total mengecualikan pembatalan; teks tidak dieksekusi sebagai formula |
| NOT-01 | FCM gagal atau izin push ditolak | Poin tetap berlaku; notifikasi in-app tetap ada |
| NOT-02 | Worker retry event yang sama | Tidak menggandakan notifikasi |
| NOT-03 | Logout/perangkat berpindah akun | Tidak mengirim detail/push ke pemilik sesi sebelumnya |
| UI-01 | Mode terang/gelap dan teks diperbesar | Tombol/nilai terbaca; QR tetap hitam-putih |
| UI-02 | Kamera ditolak dan koneksi buruk | Jalur manual tersedia; gagal/belum diketahui tidak disebut sukses |
| OPS-01 | Pemulihan database dan bukti dari backup | Relasi, catatan, audit, dan lampiran terbaca kembali |

## Tingkat pemeriksaan

- Unit domain: perhitungan total, revision efektif, ambang, semester, status akun, validasi identifier.
- Feature/integration backend dengan PostgreSQL sungguhan: policy role, constraint, transaksi, locking, idempotensi, upload privat, import, laporan, dan notifikasi.
- Flutter widget/integration: login/aktivasi, QR, scanner, pencarian, formulir, keberatan, retry, dan tema. Kamera/push dites pada perangkat Android fisik.
- Web end-to-end: login admin, import, daftar poin, pemeriksaan, semester, laporan, serta keyboard.
- QA dokumen ekspor: XLSX dibuka dan identifier/total diperiksa; PDF dirender dan diperiksa untuk header, pemotongan, pagination, serta tabel panjang.

SQLite tidak dipakai sebagai satu-satunya bukti bahwa transaksi/constraint PostgreSQL bekerja. Tidak perlu test yang sekadar mengulang implementasi; prioritaskan batas akses, konsistensi, dan kegagalan nyata.

## Deployment awal

Rancangan deployment: satu lingkungan server untuk Laravel/web/API, PostgreSQL, disk bukti privat, worker queue/outbox, dan scheduler. TLS wajib. Queue memakai database agar tidak mewajibkan Redis pada tahap awal.

Lingkungan development, staging, dan production memiliki database, credential, dan file terpisah. Staging memakai data sintetis. Release tidak memakai akun/password contoh, debug mode, atau secret dalam repository.

Website dan API memakai satu domain deployment. URL/domain nyata, provider, ukuran server, dan biaya dipilih saat persiapan deployment berdasarkan jumlah siswa dan hasil pengukuran. Tidak ada pembelian/publikasi dalam tahap perancangan.

## Dependensi operasional

1. Flutter/Dart, Android SDK, dan perangkat/emulator yang cocok dengan plugin terpilih.
2. PHP/Composer dan Node untuk build aset admin, versi yang didukung Laravel terpilih.
3. PostgreSQL dan ekstensi yang dibutuhkan constraint yang dipakai.
4. Firebase project dan dua Android app registration untuk FCM. App tanpa Google Play services tetap dapat memakai fitur inti dan notifikasi in-app; push mungkin tidak tersedia.
5. Signing key aplikasi Android dan kebijakan distribusi internal/Play Store yang dipilih sekolah.
6. Credential server, database, encryption key, dan FCM hanya pada secret/config privat.
7. Domain/TLS, penyimpanan offsite backup, serta penanggung jawab admin sekolah sebelum produksi.

Dependensi yang belum dipenuhi harus dilaporkan sebagai batasan release; jangan menandai push atau deployment siap hanya karena UI-nya ada.

## Backup dan pemulihan

- Backup database dan lampiran privat minimal harian; sasaran awal kehilangan data maksimum 24 jam.
- Simpan backup terenkripsi di lokasi terpisah dari server aplikasi. Default teknis retensi backup: 30 hari.
- Encryption key yang diperlukan memulihkan berkas/credential harus disimpan secara aman terpisah; backup tanpa kunci tidak dianggap pemulihan berhasil.
- Uji restore pada lingkungan terisolasi sebelum produksi dan setelah perubahan besar schema/storage.
- Sasaran awal pemulihan 4 jam adalah target operasional yang harus dibuktikan dengan latihan, bukan jaminan yang sudah tercapai.
- Pemulihan tidak mengirim ulang semua push lama; worker/outbox harus mempertahankan deduplikasi dan menilai akun/perangkat aktif.

## Observabilitas

Catat request ID, exception yang disanitasi, kegagalan import/export, antrean tertunda/gagal, kegagalan push, hasil backup, dan aktivitas admin penting. Jangan merekam password, QR mentah, token login, konten bukti, atau dump profil siswa dalam log umum.

Admin dapat melihat status import/export dan pemeriksaan. Health check untuk operasi tidak menampilkan credential atau data siswa. Alarm berfokus pada layanan gagal, queue tertunda, dan backup gagal.

## Pembersihan terjadwal

Scheduler membersihkan credential sementara expired, QR/redeem kedaluwarsa, selection tidak terpakai, lampiran staged yatim, file import, dan ekspor expired. Selection yang direferensikan catatan dan riwayat/audit tidak dihapus. Jalur pembersihan file harus berasal dari path storage internal, bukan input bebas pengguna.

## Kriteria sebelum release

Skenario akses dan konsistensi inti lulus, migration/backup restore diuji, dua aplikasi dibuild serta diuji pada perangkat nyata, admin dapat melakukan alur lengkap, PDF/Excel diperiksa, dan semua dependensi produksi tersedia. Uji kapasitas memakai jumlah siswa nyata yang akan dimasukkan sekolah; ukur pencarian, simpan poin, dan laporan sebelum menetapkan ukuran server.
