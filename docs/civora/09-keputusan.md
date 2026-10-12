# Log keputusan

[Kembali ke indeks](README.md)

Tanggal brainstorming: 11 Oktober 2026. Pengguna memilih jawaban melalui pilihan interaktif satu pertanyaan setiap kali. Implementasi diizinkan pada 12 Oktober 2026 setelah rancangan dan rencana tersedia.

## Keputusan yang dipilih pengguna

| ID | Keputusan |
| --- | --- |
| D-01 | Produk bernama Civora untuk pencatatan poin sekolah |
| D-02 | Tiga role: siswa, guru, admin |
| D-03 | Siswa dan guru memakai aplikasi mobile yang berbeda; admin melalui website |
| D-04 | Target versi pertama satu sekolah dan Android |
| D-05 | Prestasi dan pelanggaran dicatat terpisah; prestasi tidak otomatis menghapus pelanggaran |
| D-06 | Semua guru boleh mencatat poin untuk seluruh siswa aktif |
| D-07 | NISN untuk siswa; NIP atau kode guru unik jika tidak memiliki NIP |
| D-08 | Password awal dihasilkan sistem; bantuan guru terbatas pada password sementara, bukan password pribadi siswa |
| D-09 | Admin mengelola jurusan dan kelas serta daftar jenis poin/nilai |
| D-10 | QR siswa berganti setiap 20 detik, sekali pakai, dan hanya memilih identitas |
| D-11 | Guru dapat memilih siswa melalui pencarian nama/NISN sebagai jalur alternatif |
| D-12 | Guru memeriksa ringkasan sebelum menyimpan; QR tidak langsung memberi poin |
| D-13 | Versi pertama online |
| D-14 | Poin langsung berlaku setelah guru menyimpan |
| D-15 | Keterangan kejadian wajib; foto bukti opsional |
| D-16 | Total per semester; total baru tiap semester tanpa menghapus riwayat |
| D-17 | Admin menangani koreksi/pembatalan dengan alasan dan riwayat perubahan |
| D-18 | Siswa mengajukan keberatan lewat aplikasi, diperiksa admin bersama guru |
| D-19 | Poin tetap berlaku selama pemeriksaan belum menghasilkan koreksi disetujui |
| D-20 | Admin mengatur ambang/tindak lanjut; sistem memberi peringatan, sekolah menentukan tindakan |
| D-21 | Admin import Excel/CSV serta tambah/edit satu per satu |
| D-22 | Admin mengatur perpindahan kelas/status; siswa pindah/lulus dinonaktifkan; arsip historis tetap ada |
| D-23 | Guru dapat melihat identitas, kelas, total, dan seluruh riwayat siswa aktif |
| D-24 | Rekap per siswa/kelas/jurusan/semester dengan filter jenis dan unduhan Excel/PDF |
| D-25 | Notifikasi di aplikasi dan push; detail hanya setelah login |
| D-26 | Tema putih + teal, ramah, tenang, modern |
| D-27 | Mode terang/gelap mengikuti perangkat |
| D-28 | Flutter + Laravel + PostgreSQL |
| D-29 | Dua aplikasi Flutter, satu Laravel untuk admin/API, satu PostgreSQL dalam satu proyek |
| D-30 | Susunan layar siswa/guru/admin yang dijabarkan pada dokumen 07 |
| D-31 | Retry simpan tidak menggandakan catatan; nilai katalog lama dan penempatan historis dipertahankan |
| D-32 | Import memakai pratinjau/validasi; dokumen rancangan dipecah per topik dengan file indeks |
| D-33 | File tugas pengerjaan disusun setelah tinjauan dokumen rancangan; belum memulai implementasi |
| D-34 | Pengguna meninjau paket rancangan melalui indeks/log keputusan, menyetujuinya, dan meminta penyusunan file tugas pengerjaan |
| D-35 | Pada 12 Oktober 2026 pengguna mengizinkan implementasi, meminta kriteria goals/verifikasi, dan memilih subagent dengan review per tugas |
| D-36 | Pengguna memilih kode dikerjakan langsung di folder Civora yang sekarang, pada branch khusus, tanpa worktree tambahan |

## Rincian teknis turunan untuk ditinjau bersama paket rancangan

Rincian di bawah tidak ditanyakan sebagai pilihan tersendiri. Ia dipilih untuk membuat alur yang sudah disepakati dapat diterapkan dan diperiksa secara konkret.

| ID | Rincian | Alasan/acuan |
| --- | --- | --- |
| T-01 | Sesi pemilihan siswa 5 menit, terikat guru dan sekali dipakai untuk satu catatan | QR 20 detik tidak menghambat penulisan formulir; dokumen 03/06 |
| T-02 | QR opaque 32 byte, hanya hash di server, QR baru mencabut yang lama | Tidak memuat NISN/password dan membatasi replay; dokumen 06 |
| T-03 | Retry scan dan simpan masing-masing mempunyai kunci idempotensi | Gangguan jaringan tidak membuat selection/catatan ganda |
| T-04 | Username admin; password sementara 24 jam; password pribadi minimal 12 karakter | Tidak mewajibkan email; distribusi kredensial dibatasi waktunya |
| T-05 | Guru tidak mereset password siswa aktif; admin memverifikasi dan mereset | Membatasi bantuan akun tanpa akses permanen ke identitas siswa |
| T-06 | Keterangan/alasan 5-1.000 karakter; satu JPG/PNG maksimal 5 MB | Validasi konkret dan biaya penyimpanan terkendali |
| T-07 | Tidak ada backdating guru, nilai bebas, atau pencatatan massal siswa | Menjaga versi pertama sederhana dan penempatan jelas |
| T-08 | Maksimal satu pemeriksaan pending per catatan; pembatalan terminal | Menghindari keputusan bertentangan; dokumen 04 |
| T-09 | Import hanya akun baru, satu batch atomik, 5.000 baris/10 MB | Tidak menimpa identitas diam-diam; dokumen 03 |
| T-10 | UUID internal, revisi append-only, snapshot, constraint dan lock PostgreSQL | Riwayat serta total konsisten |
| T-11 | Blade + Livewire + Tailwind untuk admin; Material 3 Flutter; paket UI/core bersama | Admin menyatu Laravel tanpa web app kedua |
| T-12 | Sanctum mobile TTL 7 hari; admin session 30 menit idle | Fondasi sesi sederhana dan dapat dicabut |
| T-13 | Queue/cache/session database; outbox untuk event; FCM untuk push | Tidak mewajibkan Redis dan tidak kehilangan event setelah commit |
| T-14 | Storage privat awal, ekspor/import expired 7 hari, staged file 24 jam | Mencegah akses publik dan membersihkan data sementara |
| T-15 | Font Noto Sans, token warna/radius/motion pada dokumen 07 | Memperjelas tema yang sudah dipilih; kontras harus diverifikasi |
| T-16 | Backup harian/offsite terenkripsi, retensi 30 hari, target restore 4 jam | Default operasional yang harus diuji sebelum release |
| T-17 | Satu peringatan per siswa/semester/tingkat kebijakan; status dapat turun setelah koreksi | Riwayat tetap ada tanpa duplikasi peringatan |
| T-18 | Guru tetap melihat catatan buatan sendiri untuk siswa nonaktif; arsip siswa lain hanya admin | Riwayat kerja guru tidak hilang ketika siswa lulus |

Perubahan terhadap rincian teknis dapat dilakukan pada tinjauan dokumen, dengan seluruh acuan terkait diperbarui. Tidak ada layanan eksternal, dependency produk, aplikasi, atau database yang dibuat ketika menulis paket ini.
