<?php

namespace Tests\Browser;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\LoginIdentifier;
use App\Models\Major;
use App\Models\Semester;
use App\Models\Student;
use App\Models\TemporaryCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class AdminDataImportTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_browser_imports_from_template_and_activates_the_created_student(): void
    {
        $adminPassword = 'Synthetic browser administrator phrase';
        $admin = User::create([
            'role' => 'admin', 'name' => 'Synthetic Import Admin', 'password_hash' => Hash::make($adminPassword),
            'account_status' => 'active', 'must_change_password' => false,
        ]);
        LoginIdentifier::create(['user_id' => $admin->id, 'role' => 'admin', 'kind' => 'username', 'normalized_value' => 'importadmin']);

        $year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
        $semester = Semester::create(['academic_year_id' => $year->id, 'name' => 'Ganjil', 'start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'status' => 'active']);
        $major = Major::create(['code' => 'RPL', 'name' => 'Jurusan Uji', 'active' => true]);
        Classroom::create(['academic_year_id' => $year->id, 'major_id' => $major->id, 'code' => 'X-1', 'name' => 'Kelas Uji', 'grade' => 10, 'active' => true]);

        $this->browse(function (Browser $browser) use ($adminPassword, $semester): void {
            $browser->visit('/admin/login')
                ->type('#username', 'importadmin')
                ->type('#password', $adminPassword)
                ->press('Masuk')
                ->waitForLocation('/admin')
                ->visit('/admin/confirm-password')
                ->type('#current-password', $adminPassword)
                ->press('Konfirmasi')
                ->waitForLocation('/admin')
                ->visit('/admin/imports')
                ->assertSee('Template siswa CSV');

            $browser->script(<<<'JS'
                window.__civoraTemplateDone = false;
                fetch('/admin/imports/template/student/csv')
                    .then(response => response.text())
                    .then(text => {
                        window.__civoraTemplateCsv = text.replace(/^\uFEFF/, '');
                        window.__civoraTemplateDone = true;
                    });
                JS);
            $browser->waitUsing(10, 100, fn () => $browser->script('return window.__civoraTemplateDone;')[0] === true);
            self::assertStringContainsString('name,nisn,major_code,class_code', $browser->script('return window.__civoraTemplateCsv;')[0]);

            $csvPath = storage_path('framework/testing/civora-browser-students.csv');
            if (! is_dir(dirname($csvPath))) {
                mkdir(dirname($csvPath), 0777, true);
            }
            file_put_contents($csvPath, "name,nisn,major_code,class_code\nMurid Browser,0000000901,RPL,X-1\n");
            try {
                $browser->select('#import-semester', $semester->id)
                    ->attach('#import-file', $csvPath);
                $browser->waitForText('Berkas siap diperiksa: civora-browser-students.csv', 15)
                    ->press('Pratinjau berkas')
                    ->waitForText('Pratinjau batch', 15)
                    ->assertSee('Tidak ada kesalahan')
                    ->screenshot('admin-data-import-preview');

                $browser->press('Konfirmasi dan buat 1 akun')
                    ->waitForText('Import selesai.', 15)
                    ->assertSee('Password tidak tersedia pada hasil import.')
                    ->screenshot('admin-data-import-confirmed');

                $student = Student::where('nisn', '0000000901')->with('user')->firstOrFail();
                $browser->visit('/admin/students/'.$student->id)
                    ->press('Tampilkan password sementara')
                    ->waitFor('[data-credential-display]', 10);
                $temporaryPassword = $browser->script('return document.querySelector("[data-credential-display]").textContent.trim();')[0];
                self::assertNotSame('', $temporaryPassword);

                $activationScript = <<<'JS'
                    const temporaryPassword = __TEMPORARY_PASSWORD__;
                    window.__civoraActivation = {done: false};
                    (async () => {
                        try {
                            const login = await fetch('/api/v1/auth/student/login', {
                                method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                                body: JSON.stringify({nisn: '0000000901', password: temporaryPassword})
                            });
                            const loginBody = await login.json();
                            const changed = await fetch('/api/v1/auth/change-password', {
                                method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${loginBody.data.token}`},
                                body: JSON.stringify({current_password: temporaryPassword, password: 'Synthetic new student phrase', password_confirmation: 'Synthetic new student phrase'})
                            });
                            window.__civoraActivation = {done: true, loginStatus: login.status, loginMessage: loginBody.message ?? null, loginErrors: Object.keys(loginBody.errors ?? {}), changeStatus: changed.status};
                        } catch (error) {
                            window.__civoraActivation = {done: true, error: String(error)};
                        }
                    })();
                    JS;
                $temporaryPasswordJson = json_encode($temporaryPassword, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                $browser->script(str_replace('__TEMPORARY_PASSWORD__', $temporaryPasswordJson, $activationScript));
                $browser->waitUsing(15, 100, fn () => $browser->script('return window.__civoraActivation?.done === true;')[0] === true);
                $activation = $browser->script('return window.__civoraActivation;')[0];
                self::assertSame(200, $activation['loginStatus'] ?? null, json_encode($activation));
                self::assertSame(200, $activation['changeStatus'] ?? null, json_encode($activation));
                self::assertSame('active', $student->user->fresh()->account_status);
                self::assertFalse($student->user->fresh()->must_change_password);
                self::assertSame(0, TemporaryCredential::where('user_id', $student->user_id)->count());
            } finally {
                @unlink($csvPath);
            }
        });
    }
}
