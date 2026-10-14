# Firebase lokal

Konfigurasi pengembangan lokal untuk aplikasi Android siswa dan guru memakai Firebase project ID `civora-testing`. File konfigurasi berasal dari project yang sama dan terdaftar untuk package `dev.civora.debug.student` dan `dev.civora.debug.teacher`.

## Lokasi lokal

- `apps/student/android/app/google-services.json`
- `apps/teacher/android/app/google-services.json`
- `.runtime/secrets/civora-fcm-service-account.json`
- `backend/.env` berisi `CIVORA_FCM_PROJECT_ID` dan `CIVORA_FCM_SERVICE_ACCOUNT_PATH`

Semua file tersebut diabaikan Git. Service account berhasil diparse secara lokal; nilai key, token, alamat email, dan JSON credential tidak dicatat. Pengaturan Laravel efektif menunjuk ke file service account lokal dan project ID yang sama. `backend/.env.testing` dan `backend/.env.dusk.local` tidak mengatur FCM; `config:show services.fcm --env=testing` dan `--env=dusk.local` menghasilkan `null` untuk kedua nilai. Tidak ada inherited FCM environment override atau Laravel config cache saat pemeriksaan.

## OAuth backend dan CA runtime

PHP runtime proyek memakai cURL dengan OpenSSL. CA store lokal proyek berada di `.runtime/certs/cacert.pem`; `ops/dev/setup-ca.ps1` mengambil bundle dari [curl.se](https://curl.se/ca/cacert.pem) dan checksum dari [curl.se](https://curl.se/ca/cacert.pem.sha256), memverifikasi SHA-256, lalu mengatur `curl.cainfo` dan `openssl.cafile` pada `.runtime/php/php.ini`. Unduhan memakai validasi TLS bawaan Windows. Script `ops/dev/setup.ps1` memanggil helper ini setelah memastikan PHP INI tersedia. Helper hanya mengganti dua direktif CA dan mempertahankan baris INI lainnya.

Pada 14 Oktober 2026, dari root repository:

| Perintah | Exit | Hasil |
| --- | ---: | --- |
| `.\ops\dev\setup-ca.ps1` | 0 | SHA-256 `a41b5d356aea97a529fe27e0f7316d2f9d946d75927476cf9cf1b90637d00505` cocok dengan checksum official; PHP memvalidasi direktif CA dan sertifikat PEM |
| `.\ops\dev\php.ps1 -r '$bundle = realpath(ini_get("curl.cainfo")); echo json_encode(["ca_bundle_file_exists" => is_file($bundle), "curl_and_openssl_share_bundle" => $bundle === realpath(ini_get("openssl.cafile")), "bundle_has_parseable_certificate" => openssl_x509_read(file_get_contents($bundle)) !== false], JSON_THROW_ON_ERROR), PHP_EOL;'` | 0 | Ketiga pemeriksaan bernilai `true` |
| `.\ops\dev\php.ps1 'C:\Users\ASUS\Documents\ALIFKA\PROJECT\MobileApp\Civora\.runtime\qa\fcm-oauth-precheck.php'` | 0 | `success=true`, cache `array`, TLS verification aktif; token tidak dicetak |

Helper CA dijalankan ulang dengan exit 0; perbandingan menunjukkan baris PHP INI selain `curl.cainfo` dan `openssl.cafile` tetap sama. OAuth memakai implementasi `FcmGateway::accessToken` aktual. Hasil ini membuktikan pertukaran token OAuth untuk credential lokal, tetapi tidak membuktikan izin Cloud Messaging API atau pengantaran push pada perangkat. Tidak ada pesan FCM yang dikirim dalam precheck ini. Sumber bundle/checksum: [cacert.pem](https://curl.se/ca/cacert.pem), [cacert.pem.sha256](https://curl.se/ca/cacert.pem.sha256). Referensi perilaku CA: [SSL certificate verification](https://curl.se/docs/sslcerts.html) dan [CA Extract](https://curl.se/docs/caextract.html).

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
