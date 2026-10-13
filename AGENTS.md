# Panduan kerja Civora

## Preferensi model pengguna

- Orchestrator yang diminta: `gpt-6.1-sol`, reasoning effort `medium`.
- Semua subagent baru untuk implementasi, review, dan re-review: `gpt-6-luna`, reasoning effort `high`.
- Instruksi pengguna ini mengatasi pilihan model/escalation bawaan skill. Jangan mengganti model diam-diam jika tidak tersedia.
- Tool spawn tidak mengubah model chat utama. Jangan menyatakan model orchestrator sudah berubah tanpa bukti; pengaturan chat utama dipilih pengguna.

## Pengerjaan implementasi yang disetujui

- Kerjakan langsung pada branch khusus `codex/civora-implementation` di folder Civora ini, sesuai pilihan pengguna.
- Gunakan satu implementer per tugas dan reviewer terpisah. Jangan mengulang tugas yang sudah lulus pada ledger/progress.
- Acuan: `docs/civora/README.md`, `TASKS.md`, `GOALS.md`, dan `PROGRESS.md`. Baca brief serta dokumen yang terkait tugas, bukan semua dokumen setiap kali.
- Laporkan command, exit code, dan hasil verifikasi yang benar-benar dijalankan. Bedakan test terfokus setelah fix dari suite penuh sebelum fix.
- Gunakan runtime/database lokal milik proyek melalui `ops/dev`; jangan mengubah instalasi atau database pengguna di luar proyek.
- HP Android tersedia, tetapi Firebase belum tersedia. Mock tidak membuktikan push nyata; jangan membuat project/credential eksternal atau mengklaim integrasi produksi telah lulus.
