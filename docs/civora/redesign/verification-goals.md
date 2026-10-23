# Verifikasi dan goals redesign

Dokumen ini menambah bukti UI untuk goal yang sudah ada. Ia tidak mengubah jumlah 26 tugas lama, status 21/26 lulus, maupun status milestone kamera/push/restore/kapasitas. Hanya task RD yang dapat mencatat implementasi visual; [GOALS utama](../GOALS.md) dan [progress](../PROGRESS.md) tetap sumber status proyek.

## Definition of Done untuk setiap RD

- Token dan cakupan layar mengikuti [spesifikasi visual](visual-spec.md), dan perilaku mengikuti dokumen produk terkait.
- Urutan field, nama rute, endpoint/action, role, dan session/idempotency tetap sama. Regresi perilaku terfokus dijalankan jika markup/state mengubah jalur interaksi.
- Verifikasi UI meliputi tema terang/gelap sesuai cakupan, teks besar, keyboard/focus yang relevan, ukuran viewport yang relevan, target sentuh, QR hitam-putih, dan reduced motion.
- Kontras aktual dihitung/diukur dari warna implementasi untuk teks dan kontrol/state yang digunakan. Tidak menyimpulkan lulus dari mock raster.
- Laporkan command, exit code, hasil, dan batas perangkat/tema yang diperiksa. Test terfokus pascaperubahan dipisahkan dari suite penuh sebelum perubahan.
- Implementer menyelesaikan diff dan menyerahkan bukti; reviewer terpisah memeriksa kecocokan spec dan kualitas. Temuan blocking diperbaiki dan direview ulang. Tugas tetap unchecked sebelum gate lulus.
- Perubahan bermakna di-commit dan di-push ke branch kerja setelah verifikasi dan review disetujui; tulis status nyata di ledger/progress. Tidak ada commit dari tahap rencana ini.

## Pengujian visual yang dituju

Untuk mobile, gunakan test widget yang sudah tersedia dan perintah `flutter test`/`flutter analyze` dalam app atau package yang diubah. Uji perilaku yang relevan seperti alur QR, retry simpan, session, notifikasi, dan koreksi; jangan membuat test kosmetik yang hanya menyalin susunan widget. Untuk web admin, gunakan test browser/feature yang sesuai dan `npm run build` dari `backend`. Uji kontras dan responsivitas pada viewport kecil yang dipakai proyek. Kamera/QR perangkat dan push nyata tetap hanya dapat dibuktikan pada acceptance fisik; mock/build tidak cukup.

## Goals terkait

| RD | Output visual | Relasi goal lama |
| --- | --- | --- |
| RD-01 | Token bersama, navigasi, dan layar mobile inti | G2/G3; tidak menggantikan M-03/M-04/M-06 atau acceptance perangkat |
| RD-02 | Layar turunan siswa/guru dan state alur | G3; tetap menunggu kamera/aksesibilitas/native yang dicatat |
| RD-03 | Konsistensi layout admin dan layar turunan responsif | G2; tidak mengubah status A-01–A-05 |
| RD-04 | Pemeriksaan visual lintas permukaan dan dokumentasi bukti | G2/G3; tidak menuntaskan I-01/I-03/I-04 |
