# Cakupan layar dan galeri konsep

Semua papan berikut adalah gambar sintetis berlabel. “Terlihat” berarti layar direpresentasikan dalam konsep yang disetujui, bukan telah diuji di aplikasi. Keadaan lain pada cakupan implementasi adalah pola turunan dan belum ditinjau sebagai mockup tersendiri.

## Konsep mobile

| Aset | Cakupan representatif |
| --- | --- |
| ![Home, QR, tema terang](assets/mobile-home-qr-light.png) | Beranda siswa/guru dan QR; panel perak, label kelas bergaris bawah, semester polos. Konsep QR tidak memuat NISN; implementasi dan cakupan harus tetap menampilkan NISN sesuai dokumen produk. |
| ![Pencarian dan profil guru](assets/mobile-teacher-search-profile-record.png) | Pencarian siswa, profil, ringkasan tindakan guru |
| ![Review catatan](assets/mobile-record-review.png) | Ringkasan sebelum simpan dengan kartu putih dan data berkelompok; tata letak ini versi terbaru yang disetujui |
| ![Sukses dan riwayat](assets/mobile-record-success-history.png) | Keberhasilan simpan dan daftar riwayat dengan data berkelompok; versi terbaru yang disetujui |
| ![Riwayat dan detail siswa](assets/mobile-student-history-detail.png) | Riwayat dan detail catatan siswa |
| ![Keberatan dan notifikasi](assets/mobile-student-objection-notifications.png) | Pengajuan keberatan dan daftar notifikasi |
| ![Profil, tema, login siswa](assets/mobile-student-profile-theme-login.png) | Profil/pilihan tema dan login |
| ![Login dan aktivasi guru](assets/mobile-teacher-login-activation.png) | Login dan aktivasi guru |
| ![Home, riwayat, QR gelap](assets/mobile-dark-home-history-qr.png) | Contoh representatif home/riwayat/QR dalam mode gelap saja |

## Konsep admin

| Aset | Cakupan representatif |
| --- | --- |
| ![Dashboard](assets/admin-dashboard.png) | Dashboard |
| ![Daftar siswa](assets/admin-students.png) | Daftar siswa |
| ![Pratinjau import](assets/admin-students-import-preview.png) | Pratinjau import siswa |
| ![Pemeriksaan keberatan](assets/admin-student-objection-review.png) | Pemeriksaan keberatan siswa |
| ![Pemeriksaan koreksi guru](assets/admin-teacher-correction-review.png) | Pemeriksaan koreksi guru |
| ![Katalog poin](assets/admin-point-catalog.png) | Katalog poin |
| ![Laporan](assets/admin-reports.png) | Laporan |
| ![Akademik](assets/admin-academic.png) | Administrasi akademik |
| ![Ambang dan tindak lanjut](assets/admin-threshold-followups.png) | Ambang dan tindak lanjut |
| ![Daftar guru](assets/admin-teachers.png) | Daftar guru |
| ![Pengaturan](assets/admin-settings.png) | Pengaturan; teks tautan biru pada gambar tidak mengubah aturan: tautan teks mengikuti spesifikasi teal |
| ![Audit](assets/admin-audit.png) | Audit |

## Cakupan belum dirender, turunan pola

Item berikut perlu dirancang/diimplementasikan lalu diperiksa; jangan menandainya sebagai visual yang telah disetujui hanya karena pola umum tersedia.

- Mobile: izin/lifecycle kamera dan kondisi scanner tidak tersedia (bug kamera fisik belum terkonfirmasi); layar unknown-save lock dan retry; keadaan kosong, loading, offline, error; formulir koreksi guru, detail profil/notifikasi/reauth/bantuan kredensial; detail keputusan keberatan diterima/ditolak.
- Admin: login; detail siswa dan form tambah/edit; unggah-konfirmasi-hasil import; import guru; buka/tutup semester dan form jurusan; edit katalog; daftar catatan; edit kebijakan; dialog konfirmasi; tampilan admin responsif kecil; mode gelap admin.
- Lintas layar: fokus/keyboard, teks besar, validasi field, denied permission, konflik sesi/katalog, dan state jaringan. Definisi state mengikuti dokumen UI dan skenario penerimaan.

Daftar lengkap layar/aksi bisnis tetap [dokumen UI](../07-desain-ui.md). Mock tidak mengubah syarat akses maupun data yang tampil.
