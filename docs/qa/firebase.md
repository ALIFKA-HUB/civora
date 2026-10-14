# Firebase lokal

Konfigurasi pengembangan lokal untuk aplikasi Android siswa dan guru memakai Firebase project ID `civora-testing`. File konfigurasi berasal dari project yang sama dan terdaftar untuk package `dev.civora.debug.student` dan `dev.civora.debug.teacher`.

## Lokasi lokal

- `apps/student/android/app/google-services.json`
- `apps/teacher/android/app/google-services.json`
- `.runtime/secrets/civora-fcm-service-account.json`
- `backend/.env` berisi `CIVORA_FCM_PROJECT_ID` dan `CIVORA_FCM_SERVICE_ACCOUNT_PATH`

Semua file tersebut diabaikan Git. Service account berhasil diparse secara lokal; nilai key, token, alamat email, dan JSON credential tidak dicatat. Pengaturan Laravel efektif menunjuk ke file service account lokal dan project ID yang sama. `backend/.env.testing` dan `backend/.env.dusk.local` tidak mengatur FCM; `config:show services.fcm --env=testing` dan `--env=dusk.local` menghasilkan `null` untuk kedua nilai. Tidak ada inherited FCM environment override atau Laravel config cache saat pemeriksaan.

## Bukti build dan resource native

Pada 14 Oktober 2026, menggunakan Flutter dari `C:/Android/flutter/bin/flutter.bat` dan Java 21:

| Perintah | Working directory | Exit | Hasil |
| --- | --- | --- | --- |
| `C:/Android/flutter/bin/flutter.bat build apk --debug` | `apps/student` | 0 | `build/app/outputs/flutter-apk/app-debug.apk` |
| `C:/Android/flutter/bin/flutter.bat build apk --debug` | `apps/teacher` | 0 | `build/app/outputs/flutter-apk/app-debug.apk` |
| `C:/Android/sdk/cmdline-tools/latest/bin/apkanalyzer.bat manifest application-id apps/student/build/app/outputs/flutter-apk/app-debug.apk` | repository root | 0 | `dev.civora.debug.student` |
| `C:/Android/sdk/cmdline-tools/latest/bin/apkanalyzer.bat manifest application-id apps/teacher/build/app/outputs/flutter-apk/app-debug.apk` | repository root | 0 | `dev.civora.debug.teacher` |
| `./ops/dev/php.ps1 artisan config:show services.fcm` | repository root | 0 | Runtime config resolved to `civora-testing` and the ignored local service-account file |
| `./ops/dev/php.ps1 artisan config:show services.fcm --env=testing` | repository root | 0 | Both FCM values are `null` |
| `./ops/dev/php.ps1 artisan config:show services.fcm --env=dusk.local` | repository root | 0 | Both FCM values are `null` |

Google Services menghasilkan resource debug untuk masing-masing build. Resource siswa berisi app ID `1:25088653260:android:98761b96750c8321e80f69`; resource guru berisi app ID `1:25088653260:android:ff4be64e516bf332e80f69`. Keduanya menunjuk ke `civora-testing`. Nilai API key tidak dicetak atau dimasukkan ke dokumentasi.

Build APK membuktikan konfigurasi native tersambung dan terkompilasi. Build tidak mengirim push dan tidak membuktikan pengantaran FCM pada perangkat.

## Uji perangkat yang masih menunggu

Belum ada uji push fisik atau pengiriman FCM pada konfigurasi ini. Untuk menyelesaikannya, pasang APK debug pada perangkat uji, masuk dengan akun sintetis, izinkan notifikasi, pastikan perangkat terdaftar tanpa menampilkan token, lalu picu satu notifikasi sintetis melalui alur outbox lokal dan periksa notifikasi saat aplikasi foreground/background serta ketika izin ditolak. Catat versi APK dan hasil perangkat setelah langkah tersebut benar-benar dijalankan.
