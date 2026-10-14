# Mobile integration QA

M-06 provides two separate debug APKs and UI-driven Flutter integration tests. The tests talk to the Laravel API configured through `CIVORA_API_BASE_URL`; they do not replace it with a mock. Their accounts and records must be synthetic and disposable. The student test needs one active student and an existing record that has no pending review. The teacher test needs an active teacher, that same active student, and at least one active point type. The teacher test creates a record and correction request.

Create ignored `docs/qa/mobile.student.json` and `docs/qa/mobile.teacher.json` files locally. Store test-only account passwords there, never in this document or a committed test file. Example shape:

```json
{
  "CIVORA_API_BASE_URL": "http://10.0.2.2:8031/api/v1",
  "CIVORA_QA_STUDENT_NISN": "synthetic NISN",
  "CIVORA_QA_STUDENT_PASSWORD": "synthetic password",
  "CIVORA_QA_STUDENT_RECORD_NAME": "synthetic record name"
}
```

The teacher file uses the same API URL and defines `CIVORA_QA_TEACHER_IDENTIFIER`, `CIVORA_QA_TEACHER_PASSWORD`, `CIVORA_QA_STUDENT_NAME`, and `CIVORA_QA_STUDENT_NISN`. Do not use real student or staff accounts. The test names and screenshots must not include passwords, access tokens, or raw QR values.

## Local runtime

Use the project-owned PostgreSQL cluster and Laravel wrapper described in [development setup](../development.md). Prepare the fixtures only in an empty project-owned `civora_test` database. The command checks the runtime marker, PostgreSQL data directory, connection, and empty fixture tables before it creates anything. It refuses to overwrite existing private QA config files.

```powershell
./ops/dev/php.ps1 artisan civora:prepare-mobile-qa-fixtures --env=testing
```

The command creates two active synthetic accounts, one semester, one point type, and a seeded record reserved for the native student UI objection flow. It generates random passwords directly into ignored `docs/qa/mobile.*.json` files. Those files are local secrets; do not commit or share them. The teacher UI test creates a separate record and correction request. The API smoke uses those same credentials and exercises actual Laravel endpoints with the shared `ApiClient`/`SessionController`; it creates its own record through QR redemption and submits the smoke objection to that record, leaving the seeded native UI fixture without a pending review. It verifies that invariant after the smoke and never prints the raw QR token.

Start the API server in a separate PowerShell terminal and leave it running in the foreground. Run fixture preparation and Flutter commands from another terminal; do not put the blocking server command into a sequence that must continue afterward.

```powershell
./ops/dev/serve.ps1 -BrowserTest -Port 8031
```

For an Android emulator, `10.0.2.2` reaches the host loopback. The generated ignored JSON files use `http://10.0.2.2:8031/api/v1` by default.

For a physical Android device, use ADB reverse so the phone's loopback port forwards to the host's loopback server. This works with `serve.ps1` listening only on `127.0.0.1` and does not expose the development server on the LAN. First inspect connected devices and select the intended serial; always pass it explicitly so a second attached device cannot receive the forwarding rule by mistake:

```powershell
$deviceSerial = '<DEVICE_SERIAL from adb devices -l>'
adb devices -l
adb -s $deviceSerial reverse tcp:8031 tcp:8031
adb -s $deviceSerial reverse --list
```

For the physical-device run, change only `CIVORA_API_BASE_URL` in both ignored `docs/qa/mobile.student.json` and `docs/qa/mobile.teacher.json` to `http://127.0.0.1:8031/api/v1`. Keep all generated account fields local. The Flutter integration tests pass these JSON values through `--dart-define-from-file`; `CIVORA_API_BASE_URL` is read by each app's `String.fromEnvironment` configuration and passed to the shared `ApiClient`. The debug APK build must use the same device-appropriate URL explicitly with `--dart-define=CIVORA_API_BASE_URL=...`; use `10.0.2.2` for emulator APKs or `127.0.0.1` for an APK tested through the selected device's ADB reverse rule. Remove the reverse rule after the QA session with `adb -s $deviceSerial reverse --remove tcp:8031`. These commands are instructions for the later device session; they were not run during I-04 preparation.

HTTP is for local debug use. Use HTTPS for a non-loopback staging endpoint.

Run the actual API/client smoke from `apps/student` after the API server starts:

```powershell
flutter test tool/mobile_api_smoke.dart
```

This desktop smoke proves HTTP/API client behavior against the local backend. It does not exercise Flutter widget integration, native storage, camera, or Android lifecycle.

Run the tests with an attached emulator or device:

```powershell
Push-Location apps/student
flutter test integration_test/student_workflow_test.dart --dart-define-from-file=../../docs/qa/mobile.student.json
Pop-Location

Push-Location apps/teacher
flutter test integration_test/teacher_workflow_test.dart --dart-define-from-file=../../docs/qa/mobile.teacher.json
Pop-Location
```

These tests cover student login → QR display → history → objection and teacher login → manual selection → record save → correction request against the configured API. Camera QR redemption is a separate physical-device check; the manual path does not claim to verify camera decoding. QR tokens and bearer tokens are not printed by the test.

## Builds and platform coverage

| App | Android application ID | Label | Minimum Android API |
| --- | --- | --- | --- |
| Student | `dev.civora.debug.student` | Civora Siswa | 24 (resolved APK) |
| Teacher | `dev.civora.debug.teacher` | Civora Guru | 24 (resolved APK) |

Both Gradle files set the minimum to `maxOf(flutter.minSdkVersion, 23)`. The current debug APK manifests resolve to API 24 (target API 36). These IDs are local debug identities, not Play Store registrations. Debug APKs use the local debug key. No release signing key or release signing configuration is supplied.

Build with the API URL only. Do not embed the private QA login JSON in APKs:

```powershell
Push-Location apps/student
flutter build apk --debug --dart-define=CIVORA_API_BASE_URL=http://10.0.2.2:8031/api/v1 # emulator
# For a physical device with adb reverse configured, replace the URL with http://127.0.0.1:8031/api/v1
Pop-Location

Push-Location apps/teacher
flutter build apk --debug --dart-define=CIVORA_API_BASE_URL=http://10.0.2.2:8031/api/v1 # emulator
# For a physical device with adb reverse configured, replace the URL with http://127.0.0.1:8031/api/v1
Pop-Location
```

The current debug builds completed for both apps. `aapt dump badging` confirmed IDs `dev.civora.debug.student` and `dev.civora.debug.teacher`, minimum API 24, and target API 36. The API/client smoke passed against the live local Laravel server using fresh synthetic fixtures. Firebase is not configured, so permission prompts and local notifications do not prove Firebase delivery.

Record the device model, Android version/API, screen size, and UI test result after running on hardware. Verify camera permission and QR scan, portrait/landscape, keyboard visibility, poor network behavior, background/foreground, system light/dark theme, large text, notification permission, and in-app notification support when push is unavailable.

### Device run record

The native Android student and teacher integration workflows passed on a Samsung SM-S711B running Android 16/API 36 against the local testing API with synthetic fixtures. See the [Android device QA record](android-device.md) for exact commands and scope. These integration tests do not verify camera decoding, live laptop-QR scanning, lifecycle/accessibility behavior, or real FCM delivery; those checks remain pending.
