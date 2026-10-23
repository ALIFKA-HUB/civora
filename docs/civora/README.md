# Rancangan Civora

Tanggal rancangan: 11 Oktober 2026. Bahasa produk: Indonesia. Status: rancangan disetujui pengguna; persiapan implementasi dimulai 12 Oktober 2026.

Civora adalah sistem pencatatan prestasi dan pelanggaran untuk satu sekolah. Siswa memakai aplikasi Android Civora Siswa, guru memakai aplikasi Android Civora Guru, dan admin mengelola data melalui website. Kedua jenis poin dihitung terpisah per semester.

## Cara membaca

| Dokumen | Isi |
| --- | --- |
| [01-produk-dan-cakupan.md](01-produk-dan-cakupan.md) | Tujuan, batas versi pertama, platform, dan ukuran keberhasilan |
| [02-role-dan-akun.md](02-role-dan-akun.md) | Matriks akses, login, password sementara, dan status akun |
| [03-alur-pengguna.md](03-alur-pengguna.md) | Scan QR, pencarian manual, pencatatan, keberatan, import, dan perpindahan siswa |
| [04-aturan-poin.md](04-aturan-poin.md) | Nilai poin, semester, koreksi, ambang, tindak lanjut, dan laporan |
| [05-model-data.md](05-model-data.md) | Entitas, relasi, batasan, indeks, dan transaksi |
| [06-arsitektur-api-keamanan.md](06-arsitektur-api-keamanan.md) | Struktur proyek, teknologi, kontrak API, QR, berkas, dan notifikasi |
| [07-desain-ui.md](07-desain-ui.md) | Tema, token visual, navigasi, layar, dan keadaan antarmuka |
| [08-verifikasi-dan-operasi.md](08-verifikasi-dan-operasi.md) | Skenario penerimaan, deployment, backup, dan dependensi operasional |
| [09-keputusan.md](09-keputusan.md) | Keputusan hasil brainstorming dan rincian teknis turunannya |
| [Paket acuan redesign](redesign/README.md) | Spesifikasi visual dan 21 konsep yang disetujui; terpisah dari aturan domain |
| [TASKS.md](TASKS.md) | Indeks tugas backend, admin, mobile, dan integrasi/release |
| [GOALS.md](GOALS.md) | Target milestone, kriteria selesai, bukti verifikasi, dan aturan kelulusan |
| [PROGRESS.md](PROGRESS.md) | Status pengerjaan dan bukti kemajuan yang sebenarnya |

Paket rancangan telah ditinjau dan disetujui pengguna pada 11 Oktober 2026. Implementasi diizinkan pada 12 Oktober 2026 dengan subagent dan review per tugas. [Indeks tugas](TASKS.md) memuat urutan/dependensi; [GOALS.md](GOALS.md) menetapkan bukti kelulusan; [PROGRESS.md](PROGRESS.md) mencatat status aktual.

## Ringkasan keputusan

- Satu sekolah; tiga role: siswa, guru, admin.
- Dua aplikasi Android terpisah dengan Flutter. Laravel menyatukan website admin dan API. Database PostgreSQL.
- Semua guru dapat mencatat dan melihat riwayat semua siswa aktif di sekolah.
- Login siswa dengan NISN; guru dengan NIP atau kode guru unik; password awal dihasilkan sistem.
- Guru hanya dapat membantu membagikan password sementara siswa, bukan melihat password pribadi yang sudah diganti.
- QR berganti setiap 20 detik, sekali pakai, dan hanya memilih siswa. Pencarian nama/NISN tersedia sebagai alternatif.
- Versi pertama online. Poin berlaku setelah guru menyimpan; keterangan wajib, foto opsional.
- Prestasi dan pelanggaran terpisah; total dimulai kembali setiap semester, riwayat tetap tersimpan.
- Siswa mengajukan keberatan dari aplikasi; guru mengajukan koreksi; admin memutuskan dan menyimpan alasan.
- Admin mengatur ambang dan tindak lanjut; sistem memberi peringatan, sekolah menentukan tindakan.
- Admin mengimpor Excel/CSV dan mengedit data satu per satu; laporan dapat diunduh sebagai Excel/PDF.
- Siswa pindah/lulus dinonaktifkan; riwayat tetap memakai kelas dan semester saat kejadian.
- Notifikasi di aplikasi dan push; detail dibuka setelah login.
- Latar netral dengan aksen teal terbatas; mode terang/gelap mengikuti perangkat, dengan pilihan manual.

## Prioritas acuan

Dokumen 09 membedakan keputusan yang dipilih pengguna dari rincian teknis yang diturunkan saat penulisan. Aturan domain merujuk dokumen 04, akses merujuk dokumen 02, dan kontrak integrasi merujuk dokumen 06. Jika perubahan rancangan diperlukan, perbarui seluruh dokumen yang terdampak beserta log keputusan; jangan hanya mengubah tugas pengerjaan.

Untuk presentasi visual, [paket redesign](redesign/README.md) dan spesifikasi visualnya menggantikan token/pola visual lama pada dokumen 07. Alur, field, akses, data, route, dan kontrak API tetap mengikuti dokumen produk terkait; mockup bukan bukti implementasi atau perilaku.

Folder ini belum berada di repository Git ketika rancangan awal dibuat. Repository dan lingkungan eksekusi disiapkan pada tahap implementasi; status setup tidak berarti fitur aplikasi sudah selesai.
