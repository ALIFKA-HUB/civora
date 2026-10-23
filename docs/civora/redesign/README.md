# Paket acuan redesign Civora

Status: konsep visual disetujui pengguna; paket dokumentasi disetujui review independen pada 22 Oktober 2026. Implementasi redesign belum dimulai.

Paket ini menetapkan arah visual Digcy-like dengan navigasi kapsul mengambang dan menyimpan 21 papan konsep sintetis yang disetujui. Gunakan urutan acuan berikut:

1. [Spesifikasi visual](visual-spec.md) menetapkan token, bentuk, tipografi, aksesibilitas, serta batas perubahan perilaku.
2. [Cakupan layar dan galeri](screen-coverage.md) mengindeks aset dan membedakan layar yang terlihat pada konsep dari keadaan turunan yang belum ditinjau visual.
3. [Verifikasi dan goals](verification-goals.md) menjelaskan kriteria penerimaan dan relasi ke milestone utama.
4. [Log keputusan](decisions.md) mencatat keputusan desain dan batas konsep.
5. [Rencana tugas](TASKS.md) memecah implementasi menjadi tugas RD yang belum dimulai.

Konsep adalah referensi komposisi, bukan sumber data, fixture, kebijakan sekolah, atau bukti fungsi. Jika raster berbeda dari spesifikasi tertulis, spesifikasi dan aturan domain yang dirujuk menang. Semua tugas RD masih belum dicentang sampai implementasi, verifikasi relevan, dan review terpisah lulus.

Semua 21 berkas PNG dalam [`assets/`](assets/) disalin tanpa perubahan dari konsep yang dipilih. Nama berkas stabil dan deskriptif; lihat [manifest SHA-256](asset-manifest.md) untuk asal/integritas dan [galeri](screen-coverage.md) untuk cakupan/batas papan. Aset bukan bukti pixel accuracy.
