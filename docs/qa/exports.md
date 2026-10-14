# QA kesamaan ekspor I-02

**Tanggal:** 2026-10-14
**Branch:** `codex/civora-implementation`
**Cakupan:** fixture lokal sintetis, generator XLSX/PDF Civora, dan render Dompdf; bukan uji data sekolah atau produksi.

## Fixture dan kesamaan data

Test membangun 120 catatan sintetis pada semester Ganjil di dua kelas historis, ditambah catatan fixture dasar. Catatan mencakup revisi, pembatalan, nama dan keterangan panjang, teks tanpa spasi, serta teks mirip rumus (`=`, `+`, `-`, `@`). Satu catatan pada semester Genap menjadi kontrol filter. Filter yang sama (`semester_id` Ganjil) diberikan kepada `PointReportQuery`, XLSX, dan PDF.

Snapshot query berisi **121 baris**. Total efektifnya **prestasi 306** dan **pelanggaran 280**; catatan batal tetap tercantum sebagai baris, tetapi tidak dijumlahkan. Seluruh 121 baris XLSX dibandingkan kolom demi kolom dengan snapshot query. Test memeriksa jumlah NISN yang sama pada teks PDF, total kedua format, serta teks metadata semester, filter, dan zona waktu.

Pada XLSX, NISN `0012345678` tersimpan sebagai string (`TYPE_STRING`), poin sebagai angka (`TYPE_NUMERIC`), dan empat bentuk teks mirip rumus tetap string eksplisit pada kolom keterangan. Nilainya dibuka kembali melalui PhpSpreadsheet. Nilai fixture password hash, secret terenkripsi, dan token perangkat tidak muncul pada XLSX atau PDF.

## Pemeriksaan visual PDF

Sampel dibuat oleh `GeneratePointReport` Civora dengan Dompdf, memakai storage privat palsu untuk test dan disalin ke direktori lokal yang diabaikan Git. PDF terakhir memiliki **46 halaman A4 landscape**, tidak terenkripsi. Ringkasan dan filter tampil di halaman 1; halaman 2 memulai detail beserta header dan keterangan panjang; header kolom diulang pada halaman 3 dan halaman berikutnya. Nomor halaman terlihat dari `1 / 46` hingga `46 / 46`. Nama panjang, kelas dengan rangkaian karakter tanpa spasi, serta keterangan 80 karakter tanpa spasi membungkus di dalam kolom tanpa terpotong atau keluar halaman.

Render yang diperiksa berada di `.runtime/qa/exports/rendered-i02-final-artifact/`: `first-01.png` (ringkasan), `first-02.png` (header dan baris pertama), `first-03.png` (header berulang), `last-44.png` dan `last-45.png` (teks panjang/tanpa spasi), serta `last-46.png` (halaman akhir). Sampel final privat berada di `.runtime/qa/exports/i02-point-report.xlsx` dan `.runtime/qa/exports/i02-point-report.pdf`; keduanya tidak dilacak Git.

Hash SHA-256 PDF final: `C3055FEAA49318C4FB66CD0D055D1ABB474F75C3EA985F91B95ADA0A722AAFA5`.
Hash SHA-256 XLSX final: `FD1B2B528FED0E878001B064E231EF543B7ECB2992EAE216DFA1A7474277F99A`.

## Perbaikan layout

Render awal dengan keterangan ekstrem menunjukkan header detail dapat tertinggal di halaman ringkasan ketika baris pertama tidak muat di ruang tersisa. Judul detail kini memulai halaman baru. Render sesudah perbaikan menempatkan header dan baris pertama bersama, mengulang header pada halaman berikutnya, dan mempertahankan nomor halaman.

## Command dan hasil

Semua command dijalankan dari `C:\Users\ASUS\Documents\ALIFKA\PROJECT\MobileApp\Civora` pada runtime/database lokal proyek.

| Command | Exit | Hasil |
| --- | ---: | --- |
| `ops/dev/postgres.ps1 start` | 0 | PostgreSQL milik proyek siap di port 54329; `ops/dev/postgres.ps1 status` juga exit 0. |
| `ops/dev/php.ps1 artisan test --filter=ExportConsistencyTest` | 0 | 1 test lulus, 2.856 assertion, 9,319 ms. |
| `ops/dev/php.ps1 vendor/bin/pint --test tests/Feature/ExportConsistencyTest.php` | 0 | Pint lulus. |
| `git diff --check` | 0 | Tidak ada whitespace error. |
| `C:\Users\ASUS\.cache\codex-runtimes\codex-primary-runtime\dependencies\native\poppler\Library\bin\pdfinfo.exe .runtime\qa\exports\i02-point-report.pdf` | 0 | 46 halaman, A4 landscape, tidak terenkripsi. |
| `C:\Users\ASUS\.cache\codex-runtimes\codex-primary-runtime\dependencies\native\poppler\Library\bin\pdftoppm.exe -f 1 -l 3 -r 100 -png .runtime\qa\exports\i02-point-report.pdf .runtime\qa\exports\rendered-i02-final-artifact\first` dan `C:\Users\ASUS\.cache\codex-runtimes\codex-primary-runtime\dependencies\native\poppler\Library\bin\pdftoppm.exe -f 44 -l 46 -r 100 -png .runtime\qa\exports\i02-point-report.pdf .runtime\qa\exports\rendered-i02-final-artifact\last` | 0 | Halaman representatif dirender lalu diperiksa secara visual. |
| `& C:\Users\ASUS\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe -c "from pypdf import PdfReader; p=PdfReader('.runtime/qa/exports/i02-point-report.pdf').pages; print({'pages':len(p),'page2_detail':all(x in (p[1].extract_text() or '') for x in ['Detail catatan','NISN']),'page3_repeated_header':'NISN' in (p[2].extract_text() or ''),'last_footer':'46 / 46' in (p[-1].extract_text() or '')})"` | 0 | 46 halaman; halaman 2 berisi detail/header, halaman 3 mengulang header, dan halaman akhir menampilkan `46 / 46`. |

Hasil ini membuktikan kesamaan untuk fixture sintetis lokal yang dijalankan. Hasil ini tidak mengukur kapasitas produksi atau menggantikan penerimaan lintas platform I-01.

## Review independen

Reviewer terpisah menyetujui spesifikasi dan kualitas tanpa blocker. Batas cakupan: XLSX dibandingkan penuh per sel; PDF diperiksa melalui jumlah baris/NISN, total, teks terpilih, inspeksi generator/query yang sama, serta render halaman representatif. Test belum membandingkan seluruh nilai tiap baris PDF terhadap snapshot; pemeriksaan tersebut dapat diperkuat pada testing akhir.
