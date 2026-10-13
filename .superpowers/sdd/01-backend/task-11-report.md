# B-11 implementation report

## Files

- Added `backend/app/Services/Notifications/OutboxDispatcher.php`, `FcmGateway.php`, and `InvalidDeviceToken.php`.
- Added `backend/app/Jobs/DispatchCivoraNotifications.php` and `backend/app/Console/Commands/CleanupTemporaryResources.php`.
- Added `backend/app/Http/Controllers/Api/NotificationController.php` and `DeviceTokenController.php`.
- Added `backend/tests/Feature/NotificationDeliveryTest.php`, `FcmGatewayTest.php` (review round 1), and `TemporaryCleanupTest.php`.
- Updated `backend/routes/api.php`, `backend/routes/console.php`, `backend/config/services.php`, and `docs/development.md`.

## RED / GREEN

RED command, before production code:

```powershell
.\ops\dev\php.ps1 artisan test --filter='NotificationDeliveryTest|TemporaryCleanupTest'
```

Exit code: `1`. Output reported `tests: 5, passed: 0, assertions: 3`, with the expected missing dispatch job and cleanup command classes, plus the expected missing device route (`404` where `201` was expected).

GREEN command after implementation and final test updates:

```powershell
.\ops\dev\php.ps1 artisan test --filter='NotificationDeliveryTest|TemporaryCleanupTest'
```

Exit code: `0`. Output: `passed`, `5/5 tests`, `58 assertions`, `1722 ms`.

The focused tests cover event-key deduplication after a failed push/retry, unchanged point-record count, safe FCM payload fields, current Sanctum token binding, own-only notification reads, device revocation and invalid-token deactivation, inactive-account push suppression, selection and pending-proposal evidence retention, seven-day import cleanup, expired export cleanup, and aged report orphan cleanup that preserves an active generation-token candidate.

## Full-suite receipt

Command, run once after implementation:

```powershell
.\ops\dev\php.ps1 artisan test
```

The first call yielded with session ID `35337` and no output. The first poll for session `35337` also yielded with no output. The final poll completed with exit code `0`; output: `passed`, `254/254 tests`, `1007 assertions`, `67193 ms`.

## Other verification

- `.\ops\dev\php.ps1 vendor/bin/pint --test` — exit `0`, `passed`.
- `git diff --cached --check` — exit `0`, no output.
- `.\ops\dev\php.ps1 artisan route:list --path=api/v1/notifications; .\ops\dev\php.ps1 artisan route:list --path=api/v1/device-tokens; .\ops\dev\php.ps1 artisan schedule:list` — exit `0`; showed both own-only notification routes, both device-token routes, notification dispatch each minute, and cleanup daily at 03:15 UTC.

## Scope and rulings

- Recipient mapping follows the API security spec and the four existing event producers. New records notify the student; student-submitted review requests notify the recording teacher; revisions and decisions notify the affected student/requester and teacher. A teacher does not receive a notification for their own correction request submission; a later decision notifies both that requester and the record-owning student. FCM receives only a generic title/body and event type/resource ID; outbox payloads are not forwarded.
- Device registration derives `personal_access_token_id` from the authenticated Sanctum token. Push requires an active matching user, active device binding, and a valid, unexpired Sanctum token. Explicit typed FCM `FcmError`/`UNREGISTERED` responses deactivate the binding.
- Retry uses at most five attempts with 15, 30, 60, and 120 second backoffs. In-app notifications are committed idempotently before push; push errors cannot roll back business records. The queue job is rediscovered from the outbox scheduler.
- Cleanup preserves selections referenced by point records and attachments referenced by record revisions or pending review proposals. It removes only UUID-shaped server paths on the private disk. Report orphan cleanup waits seven days and skips referenced paths and current generation-token candidates with live leases.
- FCM HTTP v1 is configurable through `CIVORA_FCM_SERVICE_ACCOUNT_PATH` and optional `CIVORA_FCM_PROJECT_ID`. No Firebase project or credentials were added. Automated tests mock the gateway; real device delivery remains unverified pending I-01.

## Remaining limitation

Push delivery is at-least-once across a worker crash after FCM accepts a message but before the outbox row is marked processed. In-app notifications remain deduplicated by `(user_id, event_key)`. Real FCM and Android-device behavior still need I-01 verification.

## Review round 1 fixes

- `review_decided` now includes the record-owning student as a recipient. The regression test submits and rejects a real teacher-correction request, then verifies both the requesting teacher and owning student receive the in-app decision while the record remains at one revision.
- `FcmGateway` now deactivates a device only for a detail whose type is `google.firebase.fcm.v1.FcmError` and error code is `UNREGISTERED`. Other 404 responses raise a retryable `RuntimeException`; the delivery retry test verifies the binding remains active after a temporary gateway error.
- The gateway tests call `FcmGateway::send` through Laravel's `Http::fake` FCM POST boundary with representative typed `UNREGISTERED` and generic project `NOT_FOUND` responses. They seed a synthetic local OAuth token cache entry and use a temporary synthetic service-account JSON file, so they exercise FCM request/error handling without live OAuth, signing, Firebase credentials, or external delivery. I-01 remains unverified.

### Review-fix RED / GREEN

RED command:

```powershell
.\ops\dev\php.ps1 artisan test --filter='test_teacher_correction_decision_notifies_record_owner|FcmGatewayTest'
```

Exit code: `1`. After replacing unsupported ephemeral RSA-key generation with a cached synthetic OAuth token, output was `tests: 3, passed: 1, assertions: 5, failed: 2`. The generic project 404 was incorrectly treated as `InvalidDeviceToken`, and the teacher-requested decision omitted the student owner. The initial fixture attempt also confirmed RSA key generation is unavailable in this project PHP runtime; the cache-seeded HTTP fake boundary avoids that unrelated dependency.

GREEN command after the fixes and rejection-path fixture setup:

```powershell
.\ops\dev\php.ps1 artisan test --filter='NotificationDeliveryTest|FcmGatewayTest'
```

Exit code: `0`. Output: `passed`, `6/6 tests`, `43 assertions`, `1729 ms`. This includes the rejected teacher-correction recipient regression, active-device retention through retry, and both typed/untyped gateway 404 cases.

### Review-fix checks

- `.\ops\dev\php.ps1 vendor/bin/pint --test app/Services/Notifications/FcmGateway.php app/Services/Notifications/OutboxDispatcher.php tests/Feature/FcmGatewayTest.php tests/Feature/NotificationDeliveryTest.php` — exit `0`, `passed`.
- `git diff --check` — exit `0`, no output.
- Full suite not rerun per review-round instructions. The prior full-suite receipt above remains the only full-suite run.

### Self-review and deferred findings

The change is limited to recipient mapping, FCM error classification, and focused regressions. It preserves event idempotency, outbox retry behavior, active bindings after retryable failures, and existing payload privacy. Review findings about fifth-attempt/backoff assertions and revision-evidence cleanup coverage are intentionally deferred for the final review; no cleanup behavior changed in this round.
