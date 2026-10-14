# Android device QA record — M-06

Native integration workflows were exercised against the local Laravel testing API on one authorized physical device.

| Property | Value |
| --- | --- |
| Device | Samsung SM-S711B |
| Android | 16 (API 36) |
| ADB serial | `<DEVICE_SERIAL>` (kept only in the ignored local QA report) |
| API | Project-owned Laravel server on host loopback, port 8031 |
| Database | Project-owned `civora_test`, synthetic QA fixtures only |

For this physical-device run, the ignored `docs/qa/mobile.student.json` and `docs/qa/mobile.teacher.json` files used `http://127.0.0.1:8031/api/v1`. ADB reverse kept the server bound to host loopback:

```powershell
$deviceSerial = '<DEVICE_SERIAL from adb devices -l>'
adb -s $deviceSerial reverse tcp:8031 tcp:8031
adb -s $deviceSerial reverse --list
```

Results:

- Student UI integration from `apps/student`: `C:/Android/flutter/bin/flutter.bat test integration_test/student_workflow_test.dart --dart-define-from-file=../../docs/qa/mobile.student.json -d $deviceSerial` — session 10288, exit 0, 1 test passed (`All tests passed!`). Covered synthetic login, QR display, history, and objection against the real local API. This run preceded the shared API query fix.
- Teacher UI integration from `apps/teacher`: `C:/Android/flutter/bin/flutter.bat test integration_test/teacher_workflow_test.dart --dart-define-from-file=../../docs/qa/mobile.teacher.json -d $deviceSerial` — final session 73170, exit 0, 1 test passed (`00:13 +1: All tests passed!`) after the API query fix and test synchronization adjustment. The debug APK built and installed successfully. Covered synthetic login, manual student search, point recording, history, and correction request against the real local API.
- Aggregate review state after both flows: one pending synthetic student objection and one pending synthetic teacher correction.

The core API client regression test verifies nullable optional query filters are omitted from the outgoing URI. Focused core and teacher Dart analysis reported no issues. A former teacher test synchronization issue was corrected by waiting for dropdown existence and ensuring the correction submit button was visible after dismissing the keyboard.

Focused verification run from `packages/civora_core`: `C:/Android/flutter/bin/flutter.bat test test/api_client_test.dart` — exit 0, 13 tests passed; `C:/Android/flutter/bin/flutter.bat analyze lib/src/api_client.dart test/api_client_test.dart` — exit 0, no issues. From `apps/teacher`: `C:/Android/flutter/bin/flutter.bat test test/teacher_correction_test.dart` — exit 0, 1 test passed; `C:/Android/flutter/bin/flutter.bat analyze integration_test/teacher_workflow_test.dart test/teacher_correction_test.dart` — exit 0, no issues.

These results do not cover camera QR decoding: the student test displayed a QR but teacher search used the manual path. Live laptop-QR scanning, camera permission acceptance, lifecycle/accessibility checks, notification permissions, and real Firebase OAuth/send/delivery remain pending. Testing API configuration remained FCM-null; no queue worker or external send ran.

After later device checks, remove the reverse rule with `adb -s $deviceSerial reverse --remove tcp:8031` and stop the project-owned API server. Test credentials remain only in ignored local QA configuration files; never commit them or the debug APKs built with those credentials.
