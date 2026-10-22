# Log keputusan redesign

Status: seluruh arah visual berikut disetujui pengguna. Log ini hanya mengatur presentasi; keputusan produk/domain di `../09-keputusan.md` tetap berlaku.

| ID | Keputusan |
| --- | --- |
| RD-D01 | Tata letak mengikuti konsep Digcy-like dan memakai navigasi bawah kapsul mengambang. |
| RD-D02 | Latar terang `#F5F5F6`, kartu data putih, dan panel besar perak `#E3E5E8`; hindari tint hijau pada panel perak. |
| RD-D03 | Teks utama hitam `#151719`, label `#45484D`; angka 17/label Prestasi, bingkai scan guru, countdown/helper QR berwarna hitam pada tema terang. |
| RD-D04 | Semester teks polos sama warna dengan kelas tanpa pil hijau. Label kelas utama bergaris bawah hanya sebagai gaya, bukan perubahan route. |
| RD-D05 | Tombol utama charcoal dengan teks putih dan holder ikon bundar di ujung; navigasi terpilih berupa pil charcoal. |
| RD-D06 | Radius: kartu data 20 px, panel penekanan 24 px, input 12 px; target sentuh 48 px; skala spasi 4/8/12/16/24/32/48 px. |
| RD-D07 | Teal dibatasi untuk tautan dan teks semantik prestasi; merah untuk pelanggaran/error. Keduanya selalu disertai label yang dapat dibaca. |
| RD-D08 | Tema gelap representatif: latar `#141719`, kartu `#24292D`, raised/nav `#2E3439`, panel `#30363B`, teks `#F3F5F6`/sekunder `#BAC2C8`, primary/selected nav `#E3E5E8` pada `#172225`, teal `#63D1C1`, merah `#FF989F`. |
| RD-D09 | QR hitam di atas putih dengan quiet zone pada semua tema. |
| RD-D10 | Font Noto Sans dan satu keluarga ikon konsisten yang sudah ada; tidak ada dependensi/font baru tanpa bukti kebutuhan dan keputusan. |
| RD-D11 | Kartu data menyusun label/nilai dengan ringkas dan terbaca, bukan angka tebal tersebar. |

## Batas pembacaan konsep

- Palet ditranskripsikan dari persetujuan dan tidak berarti rasio kontras sudah terbukti. Setiap pasangan aktual tetap diverifikasi.
- Raster mungkin berbeda warna/font/ikon/teks dan dapat menghilangkan field/state. Teks spek dan domain menang.
- Konsep home/QR menghilangkan NISN; produk tetap mensyaratkan NISN pada QR.
- Papan review/sukses adalah revisi terbaru untuk kartu putih dan kelompok data. Papan lain dapat menampilkan variasi; gunakan pola tertulis.
- Papan admin settings berisi tautan biru, sementara semua tautan teks mengikuti aturan aksen teal.
- Hanya home/riwayat/QR mobile yang punya contoh gelap representatif. Tema gelap belum ditinjau per layar dan tidak ada konsep admin gelap.
- Angka, nama, nilai, dan isi papan hanya contoh sintetis; bukan fixture, aturan poin sekolah, atau kebijakan.

Data dan alur produk tidak berubah: total prestasi/pelanggaran per semester terpisah; QR 20 detik sekali pakai untuk pemilihan siswa lalu konfirmasi guru; pencarian manual tersedia; catatan online dengan opsi fallback manual; alasan 5–1.000 karakter dan satu JPG/PNG 5 MB; password 12–1.024 karakter dan sementara 24 jam; siswa NISN, guru NIP/kode; data siswa dan credential tetap privat. Keberatan pending maksimal satu per catatan, keputusan admin wajib alasan dan preview sebelum/sesudah; tidak ada sanksi otomatis; audit lama dipertahankan. Import hanya akun baru, atomik, XLSX/CSV 10 MB/5.000 baris, identifier mempertahankan nol awal. Ekspor tetap privat, memakai filter dan snapshot kelas historis; file pending bukan unduhan. Detail lengkap tetap pada spec domain.
