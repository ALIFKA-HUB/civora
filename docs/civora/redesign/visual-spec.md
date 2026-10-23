# Spesifikasi visual redesign Civora

## Arah

Pakai tata letak bersih ala Digcy dengan navigasi bawah kapsul mengambang. Hierarki berfokus pada data dan tindakan; gunakan aksen teal seperlunya. Pertahankan struktur role siswa/guru/admin dari [desain UI produk](../07-desain-ui.md).

## Token warna

| Peran | Terang | Gelap |
| --- | --- | --- |
| Latar aplikasi | `#F5F5F6` | `#141719` |
| Kartu data | `#FFFFFF` | `#24292D` |
| Panel penekanan besar | `#E3E5E8` | `#30363B` |
| Lapisan terangkat/nav | `#FFFFFF` | `#2E3439` |
| Teks utama | `#151719` | `#F3F5F6` |
| Label/teks sekunder | `#45484D` | `#BAC2C8` |
| Tombol utama | `#172225` dengan teks `#FFFFFF` | `#E3E5E8` dengan teks `#172225` |
| Pilihan nav aktif | kapsul `#172225`, teks putih | kapsul `#E3E5E8`, teks `#172225` |
| Aksen teks teal | `#0B625A` | `#63D1C1` |
| Pelanggaran/error | merah semantik dengan label; ukur pada permukaan aktual | `#FF989F` |

Pada tema terang, semester tampil sebagai teks biasa dengan warna yang sama seperti label kelas, tanpa pil hijau. Label kelas utama bergaris bawah sesuai konsep; garis bawah sendiri tidak mengubah navigasi atau menyatakan tautan. Angka 17 dan label “Prestasi”, bingkai kamera guru, countdown QR, dan helper QR memakai hitam pada tema terang. Tidak menggunakan tint hijau pada panel perak.

`#0B625A` adalah aksen teks teal terang yang disepakati untuk dipakai konsisten di kartu putih dan panel perak. Rasio luminansi nominalnya 7.22:1 terhadap putih dan 5.72:1 terhadap perak `#E3E5E8`; angka ini perhitungan warna, bukan verifikasi UI/raster. Ukur seluruh kombinasi implementasi termasuk fokus/disabled dan border sebelum acceptance.

## Bentuk dan ritme

| Elemen | Aturan |
| --- | --- |
| Navigasi bawah | Kapsul mengambang; pilihan aktif berupa pil charcoal |
| Tombol utama | Bentuk pil penuh, ikon aksi di holder lingkaran trailing |
| Kartu data | Radius 20 px, putih di atas latar netral pada terang |
| Kartu penekanan besar | Radius 24 px, perak `#E3E5E8` pada terang |
| Input | Radius 12 px |
| Target sentuh mobile | Minimum 48 × 48 logical px |
| Spasi | Skala 4, 8, 12, 16, 24, 32, 48 px |
| Motion | 150–220 ms untuk umpan balik singkat; patuhi reduced motion |

Kelompokkan label dan nilai agar cepat dipindai. Hindari angka tebal yang tersebar dan kartu bertumpuk tanpa hierarki. Gunakan satu keluarga ikon tipis yang sudah digunakan proyek; jangan migrasi paket/library demi konsep. Pertahankan font Noto Sans yang sudah dibundel, lalu sesuaikan bobot dan spasi. Font baru memerlukan bukti kebutuhan serta keputusan eksplisit.

## QR, kontras, dan aksesibilitas

QR selalu hitam di atas putih dengan quiet zone yang cukup, di kedua tema. Jangan inversi atau beri tint QR. Desain harus bertahan pada teks diperbesar, screen reader, fokus terlihat, dan target sentuh; label penting tidak boleh hanya ditandai warna. Countdown tidak diumumkan setiap detik. Uji fokus keyboard untuk web admin dan sistem reduced motion.

## Invarian fungsi

Redesign hanya mengubah penyajian. Pertahankan endpoint, Livewire action, otorisasi/role, urutan field, rute, tab/navigasi, kontrak API, key idempotensi, dan perlindungan sesi basi. Jangan mengarang tautan dari underline visual. Nilai dan total tetap dari server. Ketentuan produk tetap merujuk [alur](../03-alur-pengguna.md), [aturan poin](../04-aturan-poin.md), [role](../02-role-dan-akun.md), dan [API/keamanan](../06-arsitektur-api-keamanan.md).
