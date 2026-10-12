# Aturan poin

[Kembali ke indeks](README.md)

## Daftar poin

- Admin menetapkan kode, nama, kategori prestasi/pelanggaran, nilai bilangan bulat positif, deskripsi, dan status aktif.
- Jenis baru memiliki versi pertama. Perubahan nama/nilai/deskripsi membuat versi baru; versi lama tidak ditimpa.
- Kategori sebuah jenis tidak boleh diubah setelah pernah digunakan. Perubahan kategori dilakukan melalui jenis baru, dan jenis lama dinonaktifkan bila diperlukan.
- Guru hanya memilih jenis aktif; nilai diambil server. Tidak ada nilai bebas, multiplier, atau pilihan banyak siswa sekaligus pada versi pertama.
- Setiap catatan memuat satu siswa, satu jenis poin, satu nilai, satu keterangan, dan maksimal satu foto.
- Jenis yang telah digunakan tidak dihapus permanen; status nonaktif mencegah penggunaan baru.

## Total dan status

Total prestasi = jumlah nilai efektif catatan kategori prestasi yang masih berlaku untuk siswa dan semester terpilih.

Total pelanggaran = jumlah nilai efektif catatan kategori pelanggaran yang masih berlaku untuk siswa dan semester terpilih.

Nilai efektif adalah revisi disetujui terbaru, atau nilai awal jika belum dikoreksi. Catatan berstatus dibatalkan tidak menyumbang total. Permintaan pemeriksaan pending/ditolak tidak mengubah nilai efektif.

Tidak ada saldo bersih prestasi dikurangi pelanggaran. Tidak ada penghapusan otomatis pelanggaran setelah prestasi tertentu. Tampilan, ambang, dan ekspor harus memakai definisi total yang sama.

## Semester dan penempatan

- Satu semester aktif untuk pencatatan pada satu waktu. Semester memiliki tahun ajaran, nama, tanggal mulai/akhir, dan status draft/active/closed.
- Rentang semester dalam satu sekolah tidak tumpang tindih. Pembukaan memastikan tanggal saat ini berada dalam rentangnya dan penempatan siswa telah disiapkan.
- Tidak ada pencatatan guru pada semester draft/closed, tanggal di luar rentang aktif, atau siswa tanpa penempatan aktif.
- Total semester baru dimulai dari nol sampai ada catatan baru. Riwayat semester sebelumnya tetap dapat dipilih.
- Pindah kelas tidak memindahkan catatan lama ke kelas baru. Rekap per kelas/jurusan merujuk penempatan saat kejadian.
- Dalam laporan siswa per kelas, nilai yang ditampilkan adalah nilai yang berasal dari kelas terpilih. Total pribadi semester dapat mencakup lebih dari satu kelas jika siswa pindah kelas.

## Koreksi dan pembatalan

Admin dapat memperbaiki jenis poin/nilai berdasarkan versi daftar poin yang dipilih, keterangan, atau bukti; atau membatalkan catatan. Guru/admin tidak memasukkan nilai arbitrer di luar versi jenis poin.

Koreksi tidak mengganti siswa, guru pencatat, semester, atau penempatan asli. Salah memilih siswa ditangani dengan pembatalan beralasan dan pencatatan baru oleh guru untuk siswa yang benar. Catatan asli tidak dipindahkan kepemilikannya.

Setiap keputusan menyimpan admin, alasan, waktu, versi catatan yang diperiksa, dan perbedaan sebelum/sesudah. Catatan awal dan revisi bersifat append-only. Koreksi terhadap semester tertutup diperbolehkan untuk memperbaiki arsip dan tidak membuka semester tersebut bagi pencatatan baru.

Jika admin lain sudah mengubah catatan sejak layar pemeriksaan dibuka, keputusan lama ditolak sebagai konflik dan admin harus membaca ulang. Persetujuan perubahan catatan, keputusan permintaan, dan pembaruan status ambang adalah satu transaksi. Notifikasi hanya dijadwalkan setelah transaksi berhasil.

Pembatalan adalah status terminal untuk versi pertama. Catatan dibatalkan tidak bisa dikoreksi lagi atau diajukan keberatan baru. Salah membatalkan diselesaikan dengan proses administratif dan catatan pengganti yang jelas, bukan menghapus audit.

## Keberatan dan permintaan koreksi

- Siswa hanya mengajukan terhadap catatan miliknya; guru hanya meminta koreksi terhadap catatan yang dibuatnya.
- Jenis pemohon adalah siswa/guru; status adalah pending/approved/rejected.
- Maksimal satu pending per catatan. Setelah ditolak, pengajuan baru dengan alasan baru diperbolehkan dan riwayat permintaan sebelumnya tetap ada.
- Admin wajib memberi alasan untuk setiap keputusan, termasuk penolakan.
- Admin dapat mengoreksi langsung dengan alasan. Jika ada permintaan pending, keputusan atas permintaan tersebut harus diselesaikan dalam transaksi yang sama.
- Catatan tetap berlaku ketika permintaan pending; notifikasi bukan tanda bahwa koreksi disetujui.

## Ambang pelanggaran

Admin membuat satu kebijakan ambang per semester dengan beberapa tingkat. Setiap tingkat memiliki batas poin positif dan nama tindak lanjut, misalnya pembinaan atau pemanggilan orang tua. Contoh ini bukan aturan sekolah yang telah ditetapkan.

Suatu ambang tercapai jika total pelanggaran semester lebih besar atau sama dengan batasnya. Jika beberapa ambang terlampaui, semua tingkat yang tercapai terlihat; tingkat tertinggi menjadi status ringkas. Tidak ada sanksi otomatis.

Peringatan baru dibuat sekali ketika ambang pertama kali tercapai untuk siswa/semester/tingkat. Koreksi dapat membuat status sekarang turun di bawah ambang; riwayat peringatan dan tindak lanjut tetap ada dan ditandai tidak lagi tercapai. Jika ambang yang sama tercapai lagi, peringatan lama diperbarui tanpa membuat riwayat peringatan duplikat.

Perubahan aturan membuat versi kebijakan baru dan menghitung ulang status semester tersebut. Versi kebijakan terbaru menjadi sumber status saat ini; peringatan dari versi lama ditandai sebagai kebijakan yang sudah digantikan, bukan tetap dianggap peringatan aktif. Pemeriksaan ambang lama tidak dihapus. Admin melihat bahwa status berasal dari perubahan kebijakan, bukan kejadian baru. Kebijakan semester tertutup hanya boleh diubah melalui tindakan beralasan dan diaudit.

## Tindak lanjut

Admin mencatat tindakan yang dilakukan sekolah, tanggal, keterangan, dan status direncanakan/selesai/dibatalkan. Satu peringatan dapat memiliki beberapa tindakan. Status selesai tidak mengurangi poin. Siswa melihat informasi tindak lanjut yang terkait dirinya; guru dapat melihat status untuk konteks pembinaan.

## Pencegahan pencatatan ganda

Kunci idempotensi unik per guru dan upaya pencatatan. Payload yang sama dengan kunci sama mengembalikan catatan yang sudah dibuat; payload berbeda dengan kunci sama ditolak. Satu sesi pemilihan siswa hanya dapat menghasilkan satu catatan.

Pencatatan jenis sama terhadap siswa yang sama pada hari yang sama tidak otomatis dilarang, karena kejadian dapat berulang. Dua pengiriman untuk upaya yang sama dicegah secara teknis; pengulangan kejadian yang berbeda ditelusuri melalui keterangan, waktu, dan guru.

## Laporan dan ekspor

- Filter: semester, jurusan, kelas, siswa, prestasi/pelanggaran, rentang tanggal, dan status catatan.
- Rekap utama hanya menjumlahkan catatan yang masih berlaku. Catatan dibatalkan dapat ditampilkan sebagai detail dengan label yang jelas.
- Rekap kelas/jurusan memakai penempatan historis, tidak memakai kelas siswa saat ini untuk semua kejadian lama.
- Hasil ekspor memuat filter, waktu pembuatan, timezone sekolah, nilai prestasi/pelanggaran terpisah, dan status.
- Excel mempertahankan identifier sebagai teks dan memperlakukan teks masukan sebagai teks, bukan formula.
- PDF berisi judul sekolah, periode, filter, tabel dengan header berulang, nomor halaman, dan waktu pembuatan. Tidak ada password atau token dalam ekspor.
