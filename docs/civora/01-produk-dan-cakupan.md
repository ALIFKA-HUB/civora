# Produk dan cakupan

[Kembali ke indeks](README.md)

## Tujuan

Mempermudah guru mencatat perilaku dan prestasi siswa menggunakan daftar poin yang ditetapkan sekolah. Siswa mengetahui catatan mereka dan dapat mengajukan keberatan. Admin menjaga data akademik, akun, aturan poin, serta laporan yang konsisten.

Keberhasilan berarti guru dapat memilih siswa melalui scan atau pencarian, menambahkan konteks kejadian, dan menyimpan satu catatan yang terlihat oleh siswa serta masuk ke rekap admin. Identitas pencatat dan perubahan catatan harus dapat ditelusuri.

## Platform

| Produk | Pengguna | Fokus |
| --- | --- | --- |
| Civora Siswa | Siswa aktif | QR pribadi, ringkasan, riwayat, keberatan, notifikasi |
| Civora Guru | Guru aktif | Scan, pencarian, pencatatan, profil siswa, koreksi |
| Civora Admin | Admin | Data, akun, akademik, daftar poin, pemeriksaan, laporan |

Target mobile versi pertama adalah Android. Admin menggunakan browser desktop; halaman utama tetap responsif untuk tablet dan layar sempit. Versi minimum Android dipilih saat implementasi berdasarkan dukungan Flutter, plugin kamera, penyimpanan aman, dan FCM yang diverifikasi saat itu.

## Termasuk versi pertama

1. Login dan pergantian password awal; reset yang dikelola admin.
2. Pengelolaan siswa, guru, jurusan, kelas, tahun ajaran, dan semester.
3. Import Excel/CSV dengan pratinjau, validasi, dan konfirmasi.
4. Pengelolaan jenis prestasi/pelanggaran dan nilai poin oleh admin.
5. QR dinamis dan pencarian manual nama/NISN.
6. Pencatatan dengan keterangan wajib dan satu foto bukti opsional.
7. Riwayat dan total terpisah per semester, dengan filter dan pagination.
8. Keberatan siswa dan permintaan koreksi guru dengan keputusan admin.
9. Ambang pelanggaran, peringatan, serta pencatatan tindak lanjut oleh admin.
10. Notifikasi di aplikasi dan push.
11. Rekap siswa, kelas, jurusan, semester; ekspor Excel/PDF.
12. Riwayat perpindahan kelas dan penonaktifan siswa pindah/lulus.
13. Mode terang/gelap dan audit aktivitas penting.

## Batas versi pertama

- Bukan sistem absensi. Scan QR memilih identitas; ia tidak membuktikan kehadiran atau otomatis memberi poin.
- Tidak ada penyimpanan pencatatan offline untuk dikirim otomatis kemudian. Kegagalan jaringan harus terlihat.
- Tidak ada portal orang tua, role tambahan, chat, pembayaran, atau ranking publik siswa.
- Tidak ada pengurangan otomatis poin pelanggaran oleh prestasi.
- Tidak ada penerapan sanksi otomatis, pengenalan wajah, GPS wajib, atau penguncian perangkat siswa.
- Tidak ada pendaftaran akun publik atau pemisahan banyak sekolah.
- Publikasi Play Store, pembelian server/domain, dan pembuatan akun layanan bukan bagian dari tahap brainstorming.

## Data minimum

Siswa: nama lengkap, NISN, status, dan penempatan kelas pada semester. Guru: nama lengkap, NIP bila ada, kode guru unik, dan status. Admin menentukan jurusan serta kelas. Tidak mewajibkan nomor telepon, alamat rumah, tanggal lahir, atau foto profil untuk fungsi inti.

NISN/NIP disimpan sebagai teks agar nol awal tidak hilang saat import. Foto opsional pada versi pertama adalah bukti kejadian, bukan persyaratan profil.

## Bahasa dan waktu

- Label, pesan kesalahan, serta bantuan menggunakan bahasa Indonesia.
- Waktu server disimpan sebagai timestamp berzona waktu dalam UTC dan ditampilkan dengan zona sekolah, awalnya Asia/Jakarta.
- Semester menggunakan tanggal kalender sekolah. Pembukaan/penutupan semester adalah tindakan admin, bukan penghapusan data pada tanggal tertentu.
- Format tampilan tanggal Indonesia; nilai angka poin berupa bilangan bulat.

## Contoh hasil yang diharapkan

Guru memindai QR siswa, memeriksa nama/NISN/kelas, memilih jenis pelanggaran, mengisi keterangan, lalu menyimpan. Sistem mencatat guru dan nilai saat kejadian, memperbarui total semester, memberi notifikasi siswa, dan memasukkan kejadian dalam laporan. Jika admin kemudian mengoreksi catatan, seluruh tampilan dan ekspor menggunakan hasil koreksi yang sama, dengan riwayat perubahan tetap ada.
