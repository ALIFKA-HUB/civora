# Desain UI

[Kembali ke indeks](README.md)

## Arah desain

Civora adalah aplikasi sekolah yang ramah bagi siswa, jelas untuk pekerjaan guru, dan tertib untuk administrasi. Identitasnya putih + teal, dengan huruf sans-serif mudah dibaca, ruang yang cukup, sudut agak membulat, dan animasi singkat yang memberi umpan balik.

Desain menghindari tampilan yang mempermalukan siswa: tidak ada ranking pelanggaran publik, perbandingan antarsiswa di aplikasi siswa, atau label pribadi seperti "siswa buruk". Istilah yang digunakan adalah catatan, prestasi, pelanggaran, pemeriksaan, dan tindak lanjut.

Skill design-taste-frontend dipakai sebagai panduan warna, tipografi, konsistensi bentuk, dan hierarki. Pola landing page seperti hero besar, marquee, dan dekorasi promosi tidak diterapkan pada aplikasi sekolah. Mobile mengikuti komponen Material 3 yang disesuaikan; admin mengikuti kebutuhan formulir dan tabel.

## Token visual awal

Token ini rincian desain turunan yang akan diverifikasi pada prototipe, bukan klaim kontras yang sudah diuji pada aplikasi.

| Token | Terang | Gelap |
| --- | --- | --- |
| Background | #F7FAFA | #101919 |
| Surface | #FFFFFF | #182323 |
| Primary | #0F766E | #5EEAD4 |
| On primary | #FFFFFF | #062E29 |
| Main text | #172B2A | #EDF5F4 |
| Secondary text | #526866 | #A7BFBC |
| Border | #D3E0DE | #38514D |
| Control border | #708884 | #78938E |
| Prestasi | #166534 | #86EFAC |
| Pelanggaran/error | #B42318 | #FDA29B |
| Pending/warning | #92400E | #FCD34D |

Teal adalah aksen utama. Border biasa hanya untuk pemisah dekoratif; batas input dan kontrol penting memakai Control border atau focus ring Primary. Warna semantik dipakai hanya untuk status, selalu dengan label/ikon. Jangan mewarnai seluruh layar merah ketika siswa memiliki pelanggaran. Teks status tidak boleh bergantung pada warna saja.

Mode default mengikuti sistem; pengguna dapat memilih Terang, Gelap, atau Ikuti Perangkat dari Profil/Pengaturan. QR selalu hitam pada putih dan tidak mengikuti inversi tema.

## Tipografi, ruang, dan bentuk

- Font: Noto Sans untuk Flutter dan web, dibundel/self-host dengan lisensi font disertakan; fallback sans-serif.
- Mobile: judul layar 24 px, judul bagian 18 px, body 16 px, metadata 14 px. Angka total 28-32 px tanpa mendominasi semua layar.
- Web: judul 28 px, body 16 px, tabel 14 px dengan jarak baris yang nyaman. Jangan memakai teks sangat kecil untuk menjejalkan tabel.
- Skala ruang: 4, 8, 12, 16, 24, 32, 48 px. Padding layar mobile 16-20 px.
- Radius: input/tombol 12 px, panel ringkasan 16 px, dialog/sheet 20 px; avatar lingkaran. Tidak ada campuran bentuk tanpa aturan.
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
