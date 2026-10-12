# Alur pengguna

[Kembali ke indeks](README.md)

## A. Siswa menampilkan QR

1. Siswa aktif membuka Beranda lalu Tampilkan QR.
2. Aplikasi meminta QR dari server. Server membuat token acak yang mewakili siswa login, bukan NISN atau password dalam bentuk QR.
3. QR ditampilkan bersama nama, NISN, kelas aktif, dan hitung mundur 20 detik.
4. Ketika masa berlaku habis, aplikasi meminta token baru. QR lama tidak ditampilkan sebagai aktif saat permintaan pembaruan gagal.
5. Pembuatan token baru mencabut token lama yang belum dipakai untuk siswa tersebut, termasuk dari sesi/perangkat lain.
6. Pembaruan hanya berjalan selama layar QR terbuka dan aplikasi aktif. Kembali dari background meminta QR baru.

Token hanya boleh digunakan satu kali. Screenshot lama ditolak setelah kedaluwarsa/dicabut; berbagi QR yang masih aktif secara langsung tetap mungkin. Guru harus memeriksa identitas, dan QR tidak dipakai sebagai bukti absensi.

## B. Guru memilih siswa melalui scan

1. Guru aktif memilih Scan QR. Kamera diminta hanya saat fitur digunakan; jika izin ditolak, pencarian manual tetap tersedia.
2. Kamera membaca format QR Civora, bukan membuka URL arbitrer.
3. Guru mengirim token ke server dengan sesi login guru.
4. Server memeriksa token, waktu server, status siswa, kelas aktif, semester aktif, dan konsumsi sekali pakai secara atomik.
5. Server menandai token terpakai dan mengembalikan identitas siswa serta sesi pemilihan yang terikat pada guru tersebut.
6. Guru memeriksa nama, NISN, dan kelas sebelum melanjutkan formulir.

Sesi pemilihan berlaku 5 menit dan berbeda dari umur QR 20 detik. Jika sesi habis saat guru menulis, isian tetap berada pada formulir; guru harus memilih ulang siswa yang sama melalui scan atau pencarian sebelum menyimpan. Tidak ada perpanjangan umur QR untuk mengakomodasi formulir.

Kesalahan QR: tidak valid, kedaluwarsa, sudah dipakai, dicabut, siswa tidak aktif, atau belum ditempatkan di semester aktif. Pesan tidak menampilkan token mentah.

## C. Guru memilih siswa secara manual

1. Guru membuka Cari Siswa dan mengetik nama atau NISN; filter kelas/jurusan tersedia.
2. Hasil menunjukkan nama lengkap, NISN, kelas, dan jurusan. Daftar dipaginasi.
3. Guru memilih siswa aktif yang memiliki penempatan pada semester aktif.
4. Server membuat sesi pemilihan manual yang terikat pada guru dan siswa, dengan batas waktu yang sama dengan sesi scan.
5. Formulir pencatatan berikutnya identik dengan alur scan.

Metode manual adalah jalur resmi ketika guru mengetahui identitas siswa atau siswa tidak memiliki HP. Metode pemilihan QR/manual disimpan untuk penelusuran, bukan untuk memberi perbedaan nilai poin.

## D. Guru mencatat poin

1. Formulir menampilkan identitas siswa yang dipilih.
2. Guru memilih Prestasi atau Pelanggaran, kemudian satu jenis poin aktif. Nilai ditampilkan dan tidak dapat diketik oleh guru.
3. Guru mengisi keterangan kejadian, 5 sampai 1.000 karakter setelah trim. Satu foto JPG/PNG hingga 5 MB boleh ditambahkan.
4. Ringkasan menunjukkan siswa, jenis, nilai, keterangan, bukti, dan semester aktif. Waktu kejadian versi pertama mengikuti waktu pencatatan server; tidak ada backdating guru.
5. Guru menekan Simpan. Aplikasi membuat kunci idempotensi untuk upaya pencatatan itu dan mempertahankannya pada retry.
6. Server memeriksa role, akun, sesi pemilihan, penempatan aktif, semester, dan versi daftar poin. Catatan serta konsumsi sesi pemilihan disimpan dalam satu transaksi.
7. Catatan langsung berlaku. Server memberikan ID catatan dan total terbaru; formulir menjadi layar berhasil, bukan otomatis mengirim ulang.
8. Siswa mendapat notifikasi dalam aplikasi dan push yang dijadwalkan setelah commit.

Jika nilai daftar poin berubah ketika formulir terbuka, server meminta guru meninjau ringkasan nilai baru sebelum menyimpan. Jika kelas/semester siswa berubah, guru harus memilih siswa ulang. Tidak ada nilai lama yang dikirim klien lalu dipercaya tanpa validasi.

Jika koneksi terputus setelah menekan Simpan, aplikasi menampilkan "Status penyimpanan belum diketahui". Ia memeriksa kunci idempotensi atau mengulang permintaan dengan kunci yang sama sebelum mengizinkan pencatatan baru. Jika simpan gagal pasti, isian dipertahankan selama layar masih terbuka; tidak ada antrean offline tersembunyi.

## E. Siswa melihat dan mengajukan keberatan

1. Riwayat memuat pilihan semester, jenis, dan status catatan. Detail memuat guru pencatat, keterangan, waktu, nilai, bukti, dan riwayat keputusan yang relevan.
2. Siswa memilih Ajukan Keberatan pada catatan miliknya yang masih berlaku, menulis alasan 5 sampai 1.000 karakter, lalu mengirim.
3. Sistem menampilkan status Menunggu pemeriksaan. Poin tetap masuk total selama belum ada koreksi yang disetujui.
4. Admin membaca alasan, memeriksa catatan, dan mengonfirmasi konteks kepada guru melalui proses sekolah. Aplikasi tidak menyediakan chat.
5. Admin memilih Tolak, Setujui pembatalan, atau Setujui koreksi dan wajib memberi alasan keputusan.
6. Siswa menerima notifikasi keputusan. Jika disetujui, perubahan diterapkan atomik dan total diperbarui.

Keberatan boleh diajukan terhadap catatan semester sebelumnya yang masih berlaku. Pemeriksaan terkait riwayat tidak memerlukan login siswa yang sudah dinonaktifkan; admin tetap bisa mengoreksi arsip. Untuk satu catatan, maksimal satu permintaan pemeriksaan berstatus pending, termasuk permintaan koreksi guru.

## F. Guru meminta koreksi

Guru membuka catatan yang dibuatnya, memilih Ajukan Koreksi, menyatakan jenis koreksi/pembatalan, dan mengisi alasan. Admin memprosesnya dalam antrean yang sama dengan keberatan siswa. Guru tidak mengedit catatan langsung. Jika ada pemeriksaan pending pada catatan yang sama, guru diarahkan ke status permintaan tersebut.

## G. Admin import dan mengelola data

1. Admin mengunduh template siswa atau guru. Template siswa berisi nama, NISN, kode jurusan, dan kode kelas; semester target dipilih pada layar import.
2. Admin mengunggah XLSX atau CSV UTF-8, maksimum 10 MB dan 5.000 baris per batch.
3. Server membaca identifier sebagai teks, memeriksa kolom, format, duplikasi dalam file/database, referensi kelas/jurusan, serta semester.
4. Pratinjau menampilkan jumlah valid dan kesalahan per baris. Import hanya membuat akun baru; identifier yang sudah ada dilaporkan, bukan ditimpa diam-diam.
5. Konfirmasi hanya tersedia ketika seluruh baris valid. Proses commit satu batch atomik; kegagalan menggagalkan batch, bukan meninggalkan akun setengah jadi.
6. Hasil memuat jumlah akun dibuat dan status distribusi password sementara. Password tidak dimasukkan ke laporan error, log, atau file siswa umum.
7. Tambah/edit satu per satu tersedia untuk koreksi atau data tambahan. Pembaruan massal penempatan siswa dilakukan melalui fitur perpindahan kelas, bukan import akun baru.

## H. Semester, kelas, pindah, dan lulus

Admin menyiapkan tahun ajaran, semester, dan kelas; menempatkan siswa; lalu membuka semester. Saat semester berganti, semester sebelumnya ditutup dan semester baru dibuka dalam satu transaksi. Total baru adalah hasil perhitungan semester baru, bukan penghapusan catatan lama.

Untuk pindah kelas di semester yang sama, penempatan lama diberi tanggal akhir dan penempatan baru dimulai. Catatan lama tetap menunjuk penempatan saat kejadian. Untuk pindah/lulus, admin mengubah status siswa, mengakhiri penempatan, dan menonaktifkan akun secara atomik.

## I. Peringatan dan tindak lanjut

Setelah catatan/koreksi berlaku, sistem menghitung ulang pelanggaran semester dan menentukan ambang yang sudah dicapai. Admin melihat daftar siswa yang perlu tindak lanjut dan mencatat tindakan serta keterangannya. Siswa dan guru melihat status, tetapi tindakan ditentukan sekolah, bukan dipicu otomatis sebagai sanksi.

## J. Laporan

Admin memilih semester, kelas/jurusan/siswa, serta jenis poin. Layar menampilkan rekap dan detail yang berasal dari aturan total yang sama. Ekspor Excel/PDF menyimpan filter dan waktu pembuatan, memakai data konsisten pada saat pembuatan, lalu menyediakan unduhan privat bagi admin.
