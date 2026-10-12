# Role dan akun

[Kembali ke indeks](README.md)

## Matriks akses

| Tindakan | Siswa | Guru | Admin |
| --- | --- | --- | --- |
| Melihat profil pribadi, mengganti password, logout | Ya | Ya | Ya |
| Menampilkan QR siswa | Milik sendiri | Tidak | Tidak |
| Mencari siswa aktif | Tidak | Semua siswa aktif | Semua siswa |
| Melihat total dan riwayat poin | Milik sendiri | Semua siswa aktif | Semua siswa termasuk arsip |
| Membuka bukti kejadian | Milik sendiri | Sesuai akses catatan | Semua catatan |
| Mencatat poin | Tidak | Semua siswa aktif | Tidak melalui fitur pencatatan guru |
| Mengajukan keberatan | Catatan sendiri | Tidak | Tidak |
| Mengajukan koreksi | Tidak | Catatan buatan sendiri | Dapat mengoreksi langsung dengan alasan |
| Memutuskan keberatan/koreksi | Tidak | Tidak | Ya |
| Mengubah daftar poin dan nilai | Tidak | Tidak | Ya |
| Mengelola siswa/guru/kelas/jurusan/semester | Tidak | Tidak | Ya |
| Import dan ekspor laporan | Tidak | Tidak | Ya |
| Melihat NISN dan password sementara siswa belum aktivasi | Tidak | Ya, tercatat dalam audit | Ya, tercatat dalam audit |
| Melihat password pribadi yang telah diganti | Tidak | Tidak | Tidak |
| Menghasilkan ulang password sementara | Tidak | Meminta bantuan admin | Ya, dengan alasan |
| Menonaktifkan akun | Tidak | Tidak | Ya |
| Mengatur ambang dan mencatat tindak lanjut | Tidak | Melihat status siswa | Ya |
| Melihat audit administratif dan password sementara guru | Tidak | Tidak | Ya |

Role diperiksa server pada setiap permintaan. Menyembunyikan menu saja tidak memenuhi kontrol akses. Tidak ada role wali kelas/BK terpisah dalam versi pertama.

Status aktif dalam daftar sekolah berarti education_status siswa active dan akun tidak inactive. Siswa yang masih pending_activation tetap terlihat oleh guru untuk bantuan akun dan pencatatan manual; aktivitas sekolah tidak menunggu siswa memiliki HP atau login pertama. Namun siswa tersebut sendiri hanya dapat mengganti password/logout dan membaca identitas aktivasi, belum menampilkan QR atau memakai fitur riwayat. Guru yang belum aktivasi tidak dapat mengakses data siswa.

Guru tetap dapat membaca catatan yang dibuatnya sendiri ketika siswa sudah pindah/lulus/nonaktif, termasuk mengajukan koreksi atas catatan tersebut. Akses ke seluruh arsip siswa nonaktif milik guru lain hanya tersedia untuk admin; pencatatan baru terhadap siswa nonaktif tetap ditolak.

## Identitas login

- Siswa: NISN dan password, hanya pada endpoint login siswa.
- Guru: NIP atau kode guru unik dan password, hanya pada endpoint login guru.
- Admin: username dan password melalui website. Username admin adalah rincian teknis untuk menghindari kewajiban email pribadi.
- NIP bersifat opsional dan unik jika terisi. Kode guru wajib serta unik; tidak boleh berbenturan dengan NIP guru lain pada namespace login guru.
- ID internal tidak memakai NISN/NIP sebagai primary key. Koreksi identifier oleh admin tidak mengubah pemilik riwayat.
- Satu pengguna memiliki satu role. Tidak ada perpindahan role melalui pilihan di layar login.

## Akun awal dan aktivasi

1. Admin membuat/import akun siswa atau guru. Sistem menghasilkan password acak, bukan NISN/NIP atau pola tanggal.
2. Password untuk verifikasi disimpan dengan hashing password. Salinan sementara untuk distribusi disimpan terenkripsi, dengan akses terbatas dan audit.
3. Password sementara berlaku 24 jam sejak dihasilkan. Ini default teknis rancangan, bukan durasi QR.
4. Guru hanya dapat membuka password sementara siswa yang belum menyelesaikan aktivasi. Akses menampilkan identitas siswa, masa berlaku, dan mencatat siapa yang membuka.
5. Login pertama masuk mode terbatas yang hanya mengizinkan ganti password dan logout. QR, riwayat, atau pencatatan belum tersedia sebelum password pribadi dipasang.
6. Setelah password diganti, salinan password sementara dihapus dan akses bantuan guru menampilkan status sudah aktif tanpa password.
7. Password sementara yang kedaluwarsa dapat dihasilkan ulang oleh admin. Password pribadi tidak dapat dipulihkan atau ditampilkan.

Password pribadi minimal 12 karakter, menerima frasa sandi, tanpa pemaksaan pola simbol yang menyulitkan. Pesan login gagal tidak membocorkan apakah akun terdaftar. Percobaan login dibatasi untuk menghambat tebakan.

## Reset akun yang sudah aktif

Pengguna meminta bantuan admin, langsung atau melalui guru. Admin memverifikasi identitas melalui proses sekolah, memasukkan alasan, dan menghasilkan password sementara baru. Reset mencabut seluruh sesi lama dan QR yang belum dipakai. Login berikutnya wajib mengganti password lagi.

Guru tidak dapat mereset akun siswa aktif sendiri. Pemisahan ini membatasi kemampuan masuk sebagai siswa ketika memberikan bantuan. Setiap pembuatan, pembukaan, dan regenerasi password sementara masuk audit; isi password tidak masuk log.

## Status akun

| Status | Pengaruh |
| --- | --- |
| pending_activation | Dapat login terbatas untuk mengganti password awal |
| active | Dapat memakai fitur sesuai role |
| inactive | Login dan sesi ditolak; riwayat tetap ada |

Profil siswa menyimpan status pendidikan aktif, pindah, atau lulus secara terpisah dari status akun. Siswa pindah/lulus harus memiliki akun inactive. Guru yang keluar dinonaktifkan oleh admin tanpa menghapus catatan yang pernah dibuat.

Penonaktifan mencabut sesi mobile, sesi web terkait, token perangkat push, QR, dan sesi pemilihan yang belum selesai. Permintaan dengan token lama harus ditolak meskipun token belum mencapai waktu kedaluwarsanya.

## Admin pertama

Admin pertama dibuat melalui prosedur bootstrap lokal pada tahap implementasi. Tidak ada endpoint registrasi publik. Admin dapat mengelola akun admin lain dari website; akun admin aktif terakhir tidak boleh dinonaktifkan. Semua perubahan role administratif memerlukan autentikasi ulang dan audit.

## Privasi tampilan

Siswa tidak bisa melihat profil atau riwayat siswa lain, termasuk dengan menebak ID atau tautan lampiran. Guru tidak mendapatkan laporan massal yang dapat diunduh. Notifikasi layar kunci hanya berisi pesan umum, misalnya "Ada pembaruan catatan Civora". Detail dan bukti memerlukan login dan pemeriksaan akses.
