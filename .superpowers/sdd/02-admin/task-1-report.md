# A01 admin shell implementation report

## Scope delivered

- Added Livewire admin login, required-password activation, recent-password confirmation, and read-only dashboard pages.
- Added `admin.session`, `admin.pending`, `admin.active`, and `admin.reauthenticated` middleware aliases, the `access-admin` Gate, and `/admin/*` routes. Reused the B02 `ActivateAccount` action and `AccountSecurity` policy checks; no domain mutation logic was copied into the UI.
- Added the responsive school-admin shell with the approved Civora light/dark/system tokens, locally served Noto Sans, keyboard focus and skip-link support, a mobile drawer with Escape/backdrop close, and clearly disabled navigation for pages not delivered yet.
- Dashboard cards read review-request and active-semester threshold counts only; no ranking or write actions were added.
- Added feature and real-browser tests. The browser runner selected for A01/A05 is Laravel Dusk 8.7.1. B01 did not select a browser runner; Laravel's official Dusk documentation supports browser interaction and screenshots, and Dusk fits the repository's PHPUnit setup. Livewire 4.4.7 is supported by its official installation docs for Laravel 10+ / PHP 8.1+.

## Changed files

- `backend/app/Http/Middleware/EnsureActiveAdmin.php`
- `backend/app/Http/Middleware/EnsureAdminRecentAuthentication.php`
- `backend/app/Http/Middleware/EnsureAdminSessionIsFresh.php`
- `backend/app/Http/Middleware/EnsurePendingAdminActivation.php`
- `backend/app/Livewire/Admin/Activate.php`
- `backend/app/Livewire/Admin/ConfirmPassword.php`
- `backend/app/Livewire/Admin/Dashboard.php`
- `backend/app/Livewire/Admin/Login.php`
- `backend/app/Providers/AppServiceProvider.php`
- `backend/bootstrap/app.php`
- `backend/composer.json`
- `backend/composer.lock`
- `backend/resources/css/app.css`
- `backend/resources/css/civora-theme.css`
- `backend/resources/fonts/NotoSans-Variable.ttf`
- `backend/resources/fonts/OFL.txt`
- `backend/resources/js/app.js`
- `backend/resources/views/layouts/admin.blade.php`
- `backend/resources/views/livewire/admin/activate.blade.php`
- `backend/resources/views/livewire/admin/confirm-password.blade.php`
- `backend/resources/views/livewire/admin/dashboard.blade.php`
- `backend/resources/views/livewire/admin/login.blade.php`
- `backend/routes/web.php`
- `backend/tests/Browser/AdminShellTest.php`
- `backend/tests/DuskTestCase.php`
- `backend/tests/Feature/AdminSessionTest.php`
- `backend/vite.config.js`

## Verification receipts

Commands ran serially against the project's task-local runtime and `civora_test` database:

| Command | Exit | Result |
| --- | ---: | --- |
| `ops/dev/php.ps1 artisan test --filter=AdminSessionTest` (initial red) | 1 | 6 tests, 1 passed / 5 failed: expected admin routes did not exist yet; pre-existing last-active-admin guard passed. |
| `ops/dev/php.ps1 artisan test --filter=AdminSessionTest` (after implementation) | 0 | 7/7 tests, 34 assertions passed. Covers role denial, guest redirect, active login, password reset secrecy, pending activation boundary, 30-minute idle expiry, CSRF mismatch, recent-password confirmation, and last-admin protection. |
| `ops/dev/php.ps1 artisan test` | 0 | Full PHPUnit suite: 264/264 tests, 1,052 assertions passed in 64.1 seconds. |
| `$env:APP_ENV = $null; ops/dev/php.ps1 artisan dusk --filter=AdminShellTest` | 0 | 1 real browser test, 36 assertions passed. |
| `npm run build` | 0 | Vite 8.3.4 production build passed; bundled local Noto Sans variable font (2,049.09 kB), CSS (48.44 kB), and JS (0.26 kB). |
| `ops/dev/php.ps1 vendor/bin/pint tests/Feature/AdminSessionTest.php` | 0 | Applied Pint's import ordering and line-ending fixes to the new feature test. |
| `ops/dev/php.ps1 vendor/bin/pint --test` | 0 | Pint passed across the backend. |
| `git diff --check` | 0 | No whitespace errors. |

`ops/dev/php.ps1 artisan pint --test` was also attempted and exited 1 because Pint is not registered as an Artisan command. The project-local executable `vendor/bin/pint` was used instead. The initial all-files Pint check correctly found style issues in the new test, which were fixed before the passing check.

## Browser QA

- Chrome 155.0.8059.40 with ChromeDriver 155.0.8059.39.
- Local server: `APP_ENV=testing ops/dev/php.ps1 artisan serve --host=127.0.0.1 --port=8031`; Dusk used the ignored `backend/.env.dusk.local` pointing only at loopback and `civora_test`.
- Desktop at 1440×1000: signed in, dashboard content and account name visible, password absent from the page, light/dark/system selection applied. Chromium's system preference was forced to dark for this browser test; system mode resolved to the dark surface. Theme checks calculate text/background and button contrast, requiring at least 4.5:1 for the tested normal-size text combinations.
- Keyboard: Tab from username focuses password; Escape closes the mobile navigation drawer.
- Responsive viewport widths checked for horizontal overflow: 320, 768, 1024, and 1440 pixels. None overflowed.
- Mobile screenshot with drawer open: `backend/tests/Browser/screenshots/admin-shell-mobile.png` (320×800).
- Desktop light screenshot: `backend/tests/Browser/screenshots/admin-shell-desktop-light.png` (1440×1000).
- Dusk called `storeConsoleLog('admin-shell')`; no console log file was emitted, meaning the browser reported no console entries for the test. Screenshots and logs are ignored test artifacts.
- The Dusk ChromeDriver downloader first failed with cURL TLS error 60. Resolution used the official Chrome-for-Testing metadata/archive through Windows PowerShell with default certificate verification and stored the driver only in the project's ignored `.runtime` / `backend/vendor/laravel/dusk/bin` paths. No TLS verification was disabled and no global runtime trust was changed.
- An early browser contrast-check attempt treated all hex colors as six digits; it failed on the valid `#fff` token. The helper was corrected to expand three-digit hex, then the actual browser test passed.

## Integration notes

- A02/A03/A04 mutation routes must add `admin.reauthenticated` where recent password confirmation is required. The middleware stores the original intended URL and confirmation returns there; the confirmation window is 15 minutes.
- Navigation entries for not-yet-delivered pages remain disabled and marked “Belum tersedia”; they do not point at pretend pages.
- Fonts are self-hosted from the Google Fonts Noto Sans source with its OFL license; no runtime font fetch is required.
- Browser QA evidence is local and non-production. The test user is synthetic and fixtures are scoped to `civora_test`.
