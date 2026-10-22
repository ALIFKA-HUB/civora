# Desain UI

[Kembali ke indeks](README.md)

> **Acuan visual terkini:** [Paket redesign yang disetujui](redesign/README.md), terutama [spesifikasi visual](redesign/visual-spec.md), menggantikan token awal dan bentuk visual pada dokumen ini. Tabel layar, aksi, aksesibilitas, dan state produk di bawah tetap menjadi cakupan fungsional. Layar QR tetap wajib menampilkan NISN walau konsep raster menghilangkannya.

## Arah desain

Civora adalah aplikasi sekolah yang ramah bagi siswa, jelas untuk pekerjaan guru, dan tertib untuk administrasi. Arah visual terkini memakai latar abu netral dan charcoal, dengan teal sebagai aksen terbatas; detail palet ada di paket redesign.

Desain menghindari tampilan yang mempermalukan siswa: tidak ada ranking pelanggaran publik, perbandingan antarsiswa di aplikasi siswa, atau label pribadi seperti "siswa buruk". Istilah yang digunakan adalah catatan, prestasi, pelanggaran, pemeriksaan, dan tindak lanjut.

Skill design-taste-frontend dipakai sebagai panduan warna, tipografi, konsistensi bentuk, dan hierarki. Pola landing page seperti hero besar, marquee, dan dekorasi promosi tidak diterapkan pada aplikasi sekolah. Mobile mengikuti komponen Material 3 yang disesuaikan; admin mengikuti kebutuhan formulir dan tabel.

## Token visual redesign

Rincian token baru yang disetujui ada pada [spesifikasi redesign](redesign/visual-spec.md). Tabel berikut menandai nilai lama yang sudah digantikan agar tidak menjadi acuan implementasi.

| Token | Terang | Gelap |
| --- | --- | --- |
| Background | #F5F5F6 | #141719 |
| Data card | #FFFFFF | #24292D |
| Emphasis panel | #E3E5E8 | #30363B |
| Raised/nav layer | #FFFFFF | #2E3439 |
| Main text | #151719 | #F3F5F6 |
| Secondary text | #45484D | #BAC2C8 |
| Primary/selected nav | #172225 with white text | #172225 with #E3E5E8 text |
| Text teal | #0B625A | #63D1C1 |
| Pelanggaran/error | Ukur sesuai permukaan; selalu berlabel | #FF989F |

Palet/tokens lama tidak berlaku. Aksen teal dibatasi untuk tautan dan teks semantik prestasi. Warna semantik selalu memiliki label, tidak menjadi satu-satunya penanda status. Radius, kartu, tombol, navigasi, kontras, dan motion mengikuti spesifikasi redesign. Kontras aktual state kontrol/fokus wajib diukur; token saja tidak membuktikan lulus.

Mode default mengikuti sistem; pengguna dapat memilih Terang, Gelap, atau Ikuti Perangkat dari Profil/Pengaturan. QR selalu hitam pada putih, memakai quiet zone, dan tidak mengikuti inversi tema.

## Tipografi, ruang, dan bentuk

- Font: Noto Sans untuk Flutter dan web, dibundel/self-host dengan lisensi font disertakan; fallback sans-serif.
- Mobile: judul layar 24 px, judul bagian 18 px, body 16 px, metadata 14 px. Angka total 28-32 px tanpa mendominasi semua layar.
- Web: judul 28 px, body 16 px, tabel 14 px dengan jarak baris yang nyaman. Jangan memakai teks sangat kecil untuk menjejalkan tabel.
- Skala ruang: 4, 8, 12, 16, 24, 32, 48 px. Padding layar mobile mengikuti spesifikasi redesign.
- Radius dan bentuk mengikuti spesifikasi redesign: input 12 px, kartu data 20 px, panel emphasis 24 px, tombol pill dan nav kapsul. Tidak ada campuran bentuk tanpa aturan.
- Target sentuh mobile minimum 48 x 48 logical pixels. Aksi berbahaya tidak berdekatan dengan tombol simpan utama.
- Ikon mobile memakai satu keluarga Material Symbols; web memakai satu keluarga ikon setara yang dipilih saat fondasi. Ikon bukan pengganti label tindakan penting.
- Bayangan tipis hanya untuk membedakan lapisan seperti dialog; daftar riwayat memakai ruang/divider ringan, bukan banyak panel bertumpuk.

## Navigasi siswa

Bottom navigation: Beranda, Riwayat, Notifikasi, Profil.

| Layar | Isi dan aksi |
| --- | --- |
| Login | NISN, password, tampil/sembunyikan password, bantuan melalui guru/admin |
| Aktivasi | Password sementara, password baru, konfirmasi, penjelasan pergantian wajib |
| Beranda | Identitas/kelas, semester, dua ringkasan terpisah, tombol Tampilkan QR, catatan terbaru, status tindak lanjut |
| QR | QR besar, nama/NISN/kelas, countdown, status koneksi, tombol kembali |
| Riwayat | Filter semester/jenis/status, daftar tanggal, nama jenis, nilai, dan status pemeriksaan |
| Detail catatan | Identitas guru, keterangan, bukti, waktu, nilai efektif, riwayat koreksi, tombol keberatan |
| Ajukan keberatan | Alasan wajib, ringkasan catatan, konfirmasi bahwa poin tetap berlaku sampai keputusan |
| Notifikasi | Daftar pembaruan milik siswa, penanda belum dibaca, tautan ke detail |
| Profil | Identitas read-only, pilihan tema, ganti password, logout |

QR terbuka satu sentuhan dari Beranda, bukan tersembunyi dalam Profil. Data siswa/kelas diperbaiki admin; siswa tidak mengubah sendiri identitas yang memengaruhi login dan laporan.

## Navigasi guru

Bottom navigation: Beranda, Siswa, Riwayat, Profil. Notifikasi melalui ikon berlabel aksesibel di header.

| Layar | Isi dan aksi |
| --- | --- |
| Login/aktivasi | NIP atau kode guru, password; pergantian password awal |
| Beranda | Aksi utama Scan QR dan Cari Siswa, catatan terbaru milik guru, status permintaan koreksi |
| Scanner | Kamera, bingkai scan sederhana, petunjuk, lampu kamera jika tersedia, alternatif Cari Siswa |
| Siswa | Pencarian nama/NISN, filter kelas/jurusan, hasil nama + NISN + kelas |
| Profil siswa | Identitas, dua total, riwayat/filter semester, status tindak lanjut, tombol Catat Poin |
| Catat poin | Identitas tetap terlihat, kategori, jenis/nilai, keterangan wajib, satu foto opsional |
| Ringkasan | Siswa, jenis, nilai, semester, keterangan, bukti; tombol Simpan Poin |
| Berhasil | ID/waktu catatan, total terkini, opsi Catat Siswa Lain atau Lihat Catatan |
| Riwayat guru | Daftar buatan guru, filter siswa/semester/jenis/status |
| Koreksi | Catatan awal, usulan pembatalan/koreksi, alasan, status keputusan |
| Bantuan akun siswa | Status aktivasi; lihat password sementara melalui autentikasi ulang dan audit |
| Profil/notifikasi | Tema, password, logout; hasil keputusan terkait catatan guru |

Saat scan berhasil, tampilkan identitas sebelum formulir. Jangan langsung menerapkan jenis poin terakhir tanpa konfirmasi. Setelah simpan, jangan menyisakan tombol aktif yang mengirim catatan yang sama lagi.

## Navigasi admin

Sidebar dalam kelompok:

- Ringkasan: Dashboard.
- Data sekolah: Siswa, Guru, Akademik (jurusan, kelas, tahun ajaran, semester).
- Poin: Daftar Poin, Catatan Poin, Pemeriksaan (keberatan/koreksi), Ambang & Tindak Lanjut.
- Administrasi: Laporan, Pengaturan, Audit.

Header memuat nama sekolah, semester aktif, dan akun admin. Tampilan awal Dashboard berfokus pada antrean pemeriksaan, siswa yang mencapai ambang, dan ringkasan periode; bukan ranking siswa.

Tabel menyediakan pencarian, filter eksplisit, pagination, jumlah hasil, dan menu aksi per baris. Untuk layar sempit, sidebar menjadi drawer dan tabel yang lebar dapat digulir dengan header tetap terbaca. Filter dan tombol utama tetap bisa diakses tanpa scroll horizontal.

Layar detail siswa menampilkan identitas, penempatan sekarang, riwayat kelas, total semester, catatan, pemeriksaan, tindak lanjut, dan status akun. Penonaktifan/reset password memakai konfirmasi beralasan, bukan ikon hapus tanpa penjelasan.

Layar import: pilih template/semester, unggah, pratinjau, koreksi kesalahan file, konfirmasi, hasil. Layar pemeriksaan: alasan pemohon dan catatan asli di bagian utama, konteks/revisi di panel pendamping, keputusan dengan alasan wajib.

Layar laporan menampilkan filter, ringkasan, detail, dan status pembuatan ekspor. File yang belum selesai tidak ditampilkan sebagai unduhan berhasil.

## Keadaan antarmuka

| Keadaan | Respons |
| --- | --- |
| Loading awal | Skeleton sesuai bentuk konten; tidak menampilkan total 0 seolah hasil nyata |
| Kosong | Pesan spesifik, misalnya "Belum ada catatan pada semester ini" |
| Gagal mengambil data | Pesan singkat, tombol Coba Lagi, konten lama diberi label bila masih terlihat |
| Offline | Status jelas; QR yang tidak dapat diperbarui tidak dianggap aktif |
| Sesi habis | Arahkan login; jangan mengirim data dengan akun lain |
| QR expired/used | Beri petunjuk minta QR baru atau gunakan pencarian |
| Form tidak valid | Pesan per field dan fokus ke kesalahan pertama |
| Simpan belum diketahui | Hindari tombol pencatatan baru; cek hasil upaya dengan idempotensi |
| Konflik katalog/semester | Minta peninjauan ulang; keterangan yang ditulis dipertahankan |
| Pending review | Label "Menunggu pemeriksaan", poin tetap masuk total |
| Dibatalkan | Tampilkan alasan/status; nilai tidak masuk total |
| Izin kamera/push ditolak | Jelaskan cara mengaktifkan; pencarian/in-app tetap tersedia |

## Aksesibilitas dan motion

Kontras body ditargetkan minimal 4,5:1, komponen/nonteks penting 3:1. Nilai aktual diverifikasi untuk semua kombinasi token, termasuk fokus, placeholder, dan status. Teks mendukung pembesaran tanpa memotong nilai atau aksi.

Web mendukung keyboard, focus visible, label form yang tetap terlihat, dialog dengan focus management, dan tabel berheader semantik. Mobile menyediakan semantics label untuk scanner, countdown, dan tombol; countdown tidak diumumkan setiap detik oleh screen reader.

Motion 150-220 ms untuk perpindahan/umpan balik sederhana, mengikuti pengaturan pengurangan animasi. Tidak ada parallax, latar bergerak, atau animasi terus-menerus selain indikator proses yang memang berjalan. Keberhasilan simpan ditampilkan dengan teks, bukan animasi saja.

## Logo dan konten

Wordmark awal menggunakan "Civora", dengan sublabel Siswa/Guru untuk membedakan aplikasi. Logo sekolah opsional pada pengaturan admin. Logo ilustratif baru tidak dibuat pada tahap ini; identitas app launcher dirancang saat pengerjaan UI fondasi.

Semua angka/mock data pada prototipe diberi konteks sebagai contoh. Tidak ada nama siswa nyata atau informasi sekolah nyata dalam contoh publik, screenshot QA, atau dokumentasi.
