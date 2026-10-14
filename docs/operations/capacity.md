# Rencana pengukuran kapasitas Civora

**Status:** rencana pengukuran; belum ada hasil kapasitas atau ukuran server yang disetujui.
**Skala acuan sekolah:** 3.000 siswa dan 200 guru. Angka ini adalah jumlah data, bukan jumlah pengguna serentak atau janji kapasitas.
**Lingkungan/versi/tanggal hasil:** BELUM DISEDIAKAN / BELUM DIUKUR / BELUM DITETAPKAN.

Dokumen ini menyiapkan pengukuran I-04 pada data sintetis dan database khusus yang terisolasi. Ikuti [runbook deployment](deployment.md), [runbook backup dan restore](backup-restore.md), serta skenario pada [verifikasi dan operasi](../civora/08-verifikasi-dan-operasi.md). Jangan mengarahkan pengujian beban ke database sekolah, staging bersama, atau layanan eksternal. Jangan menjalankan beban saat target dan pemilik lingkungan belum dicatat.

## Status saat ini

| Item | Status | Pemilik | Bukti/catatan |
| --- | --- | --- | --- |
| Backend, database, dan runtime khusus pengukuran | NOT RUN | NOT ASSIGNED | ENV NOT PROVIDED; tidak ada target kapasitas terisolasi yang disetujui. |
| Dataset sintetis skala 3.000 siswa/200 guru | NOT RUN | NOT ASSIGNED | Data sekolah nyata tidak digunakan pada QA. |
| Pengukuran pencarian, simpan, import, dan laporan | NOT RUN | NOT ASSIGNED | Tidak ada latency/resource/concurrency hasil I-04 yang diklaim. |
| Anggaran kinerja yang disetujui sekolah | NOT SET | NOT ASSIGNED | Isi setelah admin sekolah dan pemilik operasi menyepakatinya. |
| Pilihan ukuran server | NOT DECIDED | NOT ASSIGNED | Tidak direkomendasikan sebelum pengukuran dan dependensi infrastruktur tersedia. |

## Prosedur yang dapat diulang

1. Buat target database dan storage privat sintetis yang terpisah dari setiap lingkungan sekolah. Catat identitas DB, host/port, data directory, volume, serta bukti bahwa target bukan database sekolah sebelum menjalankan command yang terhubung ke DB. Ikuti guard dan batas keselamatan pada [development setup](../development.md) dan prosedur identitas target pada [runbook restore](backup-restore.md). Jangan memakai `migrate:fresh`, `db:wipe`, atau seeder pada lingkungan yang tidak disposable.
2. Catat tanggal UTC, commit/release backend dan migration, versi PHP/Composer/Node/PostgreSQL, OS, tipe/jumlah core CPU, RAM, storage dan filesystem, konfigurasi worker/scheduler, queue/cache/session driver, ukuran dataset, pengaturan PHP/PostgreSQL, serta versi browser/perangkat bila dipakai. Catat nama konfigurasi dan nilai nonsecret saja; jangan menyimpan `.env`, URL berkredensial, token, password, atau identitas pribadi.
3. Siapkan data sintetis deterministik: akun 3.000 siswa dan 200 guru, semester/kelas dengan distribusi yang ditandai sebagai asumsi, serta catatan riwayat dari salah satu profil di bawah. Catat generator/seed, jumlah aktual setiap tabel, ukuran dump/database/storage sebelum run, dan distribusi nama/identifier. Gunakan akun, teks, foto, serta file sintetis; jangan menaruh data sekolah nyata pada QA publik.
4. Jalankan satu pengguna terlebih dahulu untuk memeriksa alur dan mengukur baseline. Lalu uji matriks concurrency eksploratif yang diusulkan di bawah. Matriks ini adalah hipotesis untuk menemukan titik perubahan; sekolah belum menyetujui batas performa dan angka concurrency bukan target produksi.
5. Untuk setiap skenario dan tingkat concurrency, ukur sekurangnya 30 operasi per jenis alur pada sedikitnya tiga pengulangan. Catat run terpisah; jangan menggabungkan import/export asynchronous dengan waktu respons halaman. Laporkan median, p95, maksimum, jumlah sukses/gagal, error rate, dan waktu total. Untuk operasi retry, laporkan hasil awal dan retry sebagai satu pasangan serta jumlah record yang tersimpan.
6. Pisahkan run hangat dan run dingin. Run hangat dimulai setelah lima operasi pemanasan yang hasilnya tidak masuk sampel. Run dingin memakai proses aplikasi baru dan request pertama tanpa pemanasan endpoint; catat bahwa cache OS/PostgreSQL tidak dibersihkan. Jangan mengosongkan cache OS, menghentikan server, atau mengubah konfigurasi target bersama hanya untuk membuat hasil tampak cold.
7. Ambil penggunaan CPU, working-set/RAM aplikasi dan PostgreSQL, puncak serta sisa disk untuk DB/log/file privat, jumlah koneksi aktif/menunggu, query duration/slow query, worker aktif, panjang/umur tertua `jobs` dan `outbox_events`, serta job gagal sebelum/saat/sesudah setiap run. Pakai alat monitoring standar OS/database yang tersedia di target. Catat nama alat/interval sampling; jika suatu metrik tidak tersedia, tulis `NOT CAPTURED`, jangan mengarang nilainya.
8. Hentikan run ketika target mengganggu layanan lain, ruang disk turun ke batas operator, error melonjak, atau ada ketidakpastian isolasi. Simpan hasil teredaksi dan buang target sintetis melalui prosedur disposal pemilik lingkungan.

## Profil data (asumsi yang harus disahkan)

Jumlah 3.000 siswa dan 200 guru adalah acuan yang diberikan. Lama retensi, jumlah kelas, distribusi siswa per kelas, jumlah riwayat poin, tingkat lampiran, dan ukuran file aktual belum diketahui. Angka berikut hanya profil uji awal untuk mengungkap sensitivitas ukuran data; sekolah harus mengganti atau menyetujuinya sebelum pengukuran.

| Profil asumsi | Riwayat yang disimulasikan | Perkiraan record sintetis | Lampiran sintetis |
| --- | --- | ---: | --- |
| Rendah | 1 record per siswa per semester selama 2 semester | 6.000 | 0% record berlampiran |
| Tengah | 5 record per siswa per semester selama 2 semester | 30.000 | 10% berlampiran; ukuran file uji dicatat aktual |
| Tinggi | 20 record per siswa per semester selama 2 semester | 120.000 | 50% berlampiran; ukuran file uji dicatat aktual |

Profil bukan perkiraan penggunaan sekolah. Untuk disk, catat byte file yang benar-benar dibuat, termasuk distribusi ukuran, dengan variasi file uji di bawah batas 5 MB per gambar. Sertakan satu skenario file mendekati batas 5 MB hanya bila alur penyimpanan lampiran ikut diuji. Catat ukuran backup DB dan storage terpisah; jangan mengekstrapolasi ukuran disk berdasarkan rasio yang belum diukur.

## Alur dan alamat yang diuji

Gunakan endpoint persis seperti kode saat ini. API memerlukan sesi guru aktif sintetis. `Idempotency-Key` harus unik untuk setiap operasi baru; retry memakai key dan payload yang sama.

| Alur | Permintaan/aksi | Ukuran dan hasil yang dicatat |
| --- | --- | --- |
| Pencarian guru | `GET /api/v1/teacher/students` dengan pola pencarian nama/NISN sintetis dan pagination yang sama dengan klien | Latensi respons, baris cocok, halaman, CPU/DB query time, error. Uji query tanpa hasil, satu hasil, serta hasil yang tersebar pada dataset. |
| Simpan poin | `POST /api/v1/teacher/records` dengan selection/point type aktif, deskripsi sintetis, dan `Idempotency-Key` | Latensi commit hingga respons, query/lock time, CPU, koneksi, outbox/jobs baru, hasil total. Ukur submit baru dan retry setelah hasil pertama tersedia/hilang; pastikan retry menyimpan tepat satu record. |
| Import akun | Halaman `GET /admin/imports`; gunakan alur UI preview lalu confirm yang memanggil `PreviewAccountsImport` dan `CommitAccountsImport`. Maksimum 5.000 baris dan 10 MB per file sesuai kontrak proyek. | Ukur upload, preview, validasi, commit terpisah; catat entity `student` atau `teacher`, jumlah baris aktual, byte file, jumlah accepted/rejected, waktu transaksi, CPU/RAM/DB/disk, dan hasil atomik. Gunakan file sintetis valid yang belum pernah diimpor. Jangan mengukur baris yang sama sebagai import baru. |
| Laporan | Halaman `GET /admin/reports`; ukur pemuatan ringkasan/filter secara terpisah dari permintaan ekspor XLSX/PDF yang dijalankan worker | Catat filter/semester, record yang dipindai/dihasilkan, waktu halaman, waktu antre hingga selesai, waktu worker, ukuran file, penggunaan resource, umur/panjang antrean, dan status export. Download melalui `/admin/reports/exports/{reportExport}/download` hanya dari akun admin sintetis pemilik export. |

Import memakai satu transaksi batch dan tidak boleh dibagi menjadi batch parsial saat profil menguji batas 5.000 baris. Profil uji boundary dapat memakai 4.999, 5.000, dan 5.001 baris sintetis untuk mencatat validasi batas; hanya batas yang didukung kontrak boleh dikonfirmasi. Untuk report, jalankan dataset riwayat rendah/tengah/tinggi dengan filter lebar dan sempit, lalu cocokkan jumlah serta total terhadap query layar.

## Matriks concurrency eksploratif

Jalankan secara bertahap pada lingkungan terisolasi, dengan 1 pengguna sebagai baseline, lalu 5, 10, 20, dan 50 sesi sintetis hanya jika tingkat sebelumnya stabil. Tingkat tersebut adalah hipotesis pengujian, bukan jumlah pengguna serentak yang dijanjikan Civora. Catat concurrency aktual yang tercapai, ramp-up, durasi, jumlah permintaan, dan pemisahan operasi baca/tulis. Import 5.000 baris adalah satu batch admin; jangan mengirim beberapa batch import serentak sebagai asumsi perilaku sekolah tanpa persetujuan sekolah.

Untuk setiap tingkat, uji pencarian dan simpan dengan campuran terpisah, lalu antrekan satu laporan pada satu waktu. Setelah seri berurutan, admin sekolah/pemilik operasi dapat menyetujui eksperimen tambahan dengan pencarian + pencatatan bersamaan. Hindari pola writer yang semua menulis pada siswa/semester sama kecuali ditandai sebagai uji contention khusus; bandingkan dengan siswa sintetis berbeda.

Sebelum run pertama, isi anggaran kinerja yang disepakati (bukan rekomendasi tersirat dari dokumen ini):

| Ukuran keberhasilan | Batas yang disetujui | Pemberi persetujuan/tanggal |
| --- | --- | --- |
| p95 pencarian (hangat/dingin) | BELUM DISETUJUI | NOT ASSIGNED |
| p95 simpan poin termasuk retry (hangat/dingin) | BELUM DISETUJUI | NOT ASSIGNED |
| waktu preview/commit import 5.000 baris | BELUM DISETUJUI | NOT ASSIGNED |
| waktu laporan/antrean ekspor dan batas queue | BELUM DISETUJUI | NOT ASSIGNED |
| batas CPU/RAM dan sisa disk | BELUM DISETUJUI | NOT ASSIGNED |
| error rate dan batas transaksi gagal | BELUM DISETUJUI | NOT ASSIGNED |

Jangan memilih ukuran server berdasarkan p95 tanpa batas yang disetujui, kapasitas puncak yang dimodelkan, headroom, backup/restore, dan batas disk/queue yang disetujui. Catat titik pertama yang melewati batas, penyebab terukur, konfigurasi setelah perubahan, dan run ulang yang relevan.

## Formulir hasil setiap run

Salin satu formulir per kombinasi profil/alur/concurrency. Catat angka aktual; gunakan `NOT RUN`, `NOT CAPTURED`, atau `NOT APPLICABLE` jika benar.

| Field | Nilai |
| --- | --- |
| Run ID / operator / tanggal UTC | NOT RUN / NOT ASSIGNED |
| Target terisolasi dan bukti identitas DB | ENV NOT PROVIDED |
| Backend commit/release / migration / konfigurasi nonsecret | NOT CAPTURED |
| OS, CPU, RAM, storage dan alat monitoring | NOT CAPTURED |
| Profil data, tabel/record aktual, volume lampiran, ukuran DB/storage | NOT CAPTURED |
| Alur, endpoint/halaman, warm/cold, request count, concurrency/ramp-up | NOT RUN |
| Median / p95 / maksimum / error % / hasil idempotency | NOT RUN |
| CPU/RAM/disk/koneksi/query time/queue/outbox | NOT CAPTURED |
| Anggaran disetujui dan pass/fail terhadap anggaran | BELUM DISETUJUI |
| Temuan, keputusan ukuran server, penanggung jawab/tanggal tindak lanjut | NOT DECIDED / NOT ASSIGNED |

Sampai seluruh pengukuran, penerimaan sekolah, dan dependensi produksi mendapat bukti, status I-04 tetap pending dan release berstatus NO-GO. Lihat [checklist release](../qa/release-checklist.md).
