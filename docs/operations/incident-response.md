# Runbook respons insiden Civora

Status: panduan persiapan; belum ada lingkungan produksi atau kanal alarm yang dikonfigurasi. Pemilik Civora harus menetapkan operator utama/pengganti, jalur kontak internal, penyimpan bukti, dan kewenangan keputusan sebelum layanan sekolah dipakai. Jangan memasukkan credential atau data siswa ke catatan insiden umum.

## Prioritas dan bukti awal

Anggap sebagai insiden segera bila terjadi dugaan akses akun/lampiran lintas pengguna, kebocoran `APP_KEY`/password DB/FCM/signing key, database atau disk privat hilang/berubah, atau backup tidak dapat dibaca. Gangguan worker, scheduler, backup, FCM, disk penuh, atau pertumbuhan queue yang tidak terjelaskan memerlukan triase pada giliran operasi yang sama.

Catat waktu UTC, pelapor, release, dampak layanan, request ID yang disanitasi, gejala, perubahan terakhir, dan operator yang mengambil tindakan. Pertahankan log/audit dan metadata backup di lokasi terbatas. Hindari dump tabel, password, header Authorization, raw QR, session cookie, bukti foto, atau identitas siswa ke tiket/chat/log bersama. Jangan menghapus log atau mengganti kunci sebelum bukti yang diperlukan diamankan.

## Kehilangan layanan, queue tertunda, atau backup gagal

1. Periksa `/up` dan `/api/v1/health`, lalu `pg_isready` dan `select 1` secara terpisah. Health HTTP hanya membuktikan aplikasi memberi respons. Periksa status process manager web/PHP, worker dan scheduler, kapasitas volume privat, serta konektivitas DB.
2. Periksa umur queue tertua, jumlah `failed_jobs`, outbox pending/next attempt, dan event berulang gagal. Aplikasi memindai paling banyak 100 event outbox siap setiap menit. Job outbox memiliki satu percobaan queue; kegagalan gateway dihitung di record event, dicoba lagi pada jeda 15/30/60/120 detik, lalu berhenti setelah kegagalan kelima. Job ekspor mempunyai dua percobaan dan akan gagal bila retry habis. Jangan menghapus `jobs`, `failed_jobs`, atau `outbox_events` untuk mengosongkan antrean.
3. Bila backup terakhir gagal, pastikan alarm benar-benar sampai ke penerima dan eskalasikan ke operator storage/DB. Selama belum ada backup baru tervalidasi, kurangi perubahan yang berisiko dan siapkan snapshot sesuai [runbook backup dan restore](backup-restore.md) setelah sumber stabil. Backup yang tidak lengkap atau tidak dapat didekripsi tidak dihitung sebagai backup sukses.
4. Bila worker mati, periksa error sanitized dan DB/volume sebelum restart. Restart worker satu instance, amati satu batch, dan pastikan umur queue bergerak turun. Outbox menambahkan notifikasi in-app idempoten sebelum push; retry dapat mengulangi push bila proses sempat gagal setelah mengirim ke FCM namun sebelum menandai event processed. Jangan menjanjikan push exactly-once.
5. Bila cleanup gagal, cek scheduler berjalan, izin storage dan DB, serta log. Jangan menghapus file langsung berdasarkan path dari request/DB tanpa memeriksa referensi. Command cleanup menjaga selection yang memiliki record dan lampiran yang dipakai revision atau review pending; audit/riwayat tidak dibersihkan oleh command ini. Sediakan ruang dengan prosedur storage yang menjaga pasangan backup dan file aktif.

Alarm email/chat/monitoring belum diimplementasikan di repo. Sebelum produksi, uji alarm nyata untuk worker/scheduler mati, backup gagal/lebih tua 24 jam, queue/outbox lewat ambang, event `attempts >= 5`, kegagalan job, dan ruang disk. Dokumentasikan penerima, waktu eskalasi, dan pemilik tindak lanjut.

## Dugaan akses tanpa izin atau secret bocor

1. Eskalasikan kepada penanggung jawab sekolah dan operator keamanan melalui kanal internal yang ditetapkan. Jika akses sedang berlangsung, isolasi endpoint/admin melalui control plane hosting atau batasi ingress; jangan mengandalkan hanya perubahan UI.
2. Pertahankan log, audit, request ID, waktu, dan snapshot metadata yang diperlukan. Batasi akses bukti dan identifikasi rentang record/akun/perangkat yang mungkin terdampak dari audit terkontrol.
3. Cabut sesi/token terkait dari alur admin yang tersedia dan nonaktifkan device binding bila relevan. Rotasi password DB/secret FCM pada penyedia terkait dan deploy nilai baru melalui secret store. Jangan menuliskan secret lama/baru dalam catatan.
4. Jika `APP_KEY` diduga bocor, perlakukan semua ciphertext database yang dapat dibukanya sebagai terdampak. `APP_PREVIOUS_KEYS` memungkinkan dekripsi ciphertext lama dan bukan cara aman untuk menghilangkan key yang bocor. Tidak ada command repo untuk re-enkripsi massal temporary credential; rencanakan maintenance, rotasi key, penghentian sesi/cookie lama, dan penanganan `temporary_credentials` melalui prosedur teruji sebelum memakai key baru. Jangan hapus key lama sebelum data sah yang diperlukan berhasil dire-enkripsi atau dinyatakan kedaluwarsa/dirotasi.
5. Bila Android signing key bocor, hilang, atau dicurigai disalahgunakan, hentikan distribusi dan ikuti kebijakan signing/distribusi yang dipilih sekolah/platform; status dan jalur rotasinya bergantung pada metode penerbitan yang belum ditentukan. Jangan membuat key pengganti lalu mengklaim aplikasi lama dapat diperbarui.
6. Tinjau akses backup/offsite dan key dekripsi. Bila ada akses tidak sah, hentikan ekspor dan rotasi kredensial storage melalui provider. Restore hanya dari snapshot yang integritas, asal, dan custody key-nya dapat dibuktikan.
7. Sebelum layanan dibuka, verifikasi secret baru terpasang pada seluruh web/worker/scheduler, uji login dan akses resource sah/tidak sah, pastikan tidak ada key di log, lalu catat keputusan pemulihan dan pemiliknya.

## Database atau disk privat rusak/hilang

Aktifkan maintenance dan hentikan worker serta scheduler untuk mencegah perubahan baru. Jangan menimpa sumber yang rusak. Simpan bukti/metadata, identifikasi timestamp pasangan backup database+disk privat yang sama, dan hitung perkiraan kehilangan data. Beritahu pemilik sekolah tentang dampak serta pilihan titik pemulihan.

Ikuti restore terisolasi terlebih dahulu sebagaimana [runbook backup dan restore](backup-restore.md). Verifikasi checksum, key, migrasi, hitungan record/revision/audit, akses lampiran, dan isolation. Target awal RPO 24 jam/RTO 4 jam belum terbukti. Setelah hasil disetujui pemilik operasi, susun rencana perpindahan yang menjaga backup sumber dan DNS/storage/database endpoint terkendali; catat keputusan manusia sebelum mengarahkan trafik. Dokumen ini tidak memberikan izin untuk deploy/restore ke production.

## Penutupan

Tutup insiden setelah dampak dan rentang waktunya dipahami, akses/secret tertangani, backup berikutnya tervalidasi, alarm penerima dibuktikan, dan tindak lanjut memiliki pemilik serta tanggal. Catat akar masalah dan perubahan runbook yang diperlukan tanpa menyalin data personal. Tandai target RPO/RTO sebagai tercapai hanya dari latihan berwaktu yang memenuhi prosedur, bukan dari estimasi.
