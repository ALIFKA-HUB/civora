<?php

namespace Tests\Browser;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\LoginIdentifier;
use App\Models\Major;
use App\Models\PointTypeVersion;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TemporaryCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class AdminSchoolWorkflowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_admin_imports_opens_semester_records_reviews_and_runs_a_report(): void
    {
        $adminPassword = 'Synthetic browser administrator phrase';
        $admin = User::create([
            'role' => 'admin', 'name' => 'Synthetic Workflow Admin', 'password_hash' => Hash::make($adminPassword),
            'account_status' => 'active', 'must_change_password' => false,
        ]);
        LoginIdentifier::create(['user_id' => $admin->id, 'role' => 'admin', 'kind' => 'username', 'normalized_value' => 'workflowadmin']);

        $year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
        $semester = Semester::create(['academic_year_id' => $year->id, 'name' => 'Ganjil', 'start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'status' => 'draft']);
        $major = Major::create(['code' => 'RPL', 'name' => 'Jurusan Uji', 'active' => true]);
        Classroom::create(['academic_year_id' => $year->id, 'major_id' => $major->id, 'code' => 'X-1', 'name' => 'Kelas Uji', 'grade' => 10, 'active' => true]);

        $studentCsv = storage_path('framework/testing/civora-workflow-student.csv');
        $teacherCsv = storage_path('framework/testing/civora-workflow-teacher.csv');
        foreach ([$studentCsv, $teacherCsv] as $path) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
        }
        file_put_contents($studentCsv, "name,nisn,major_code,class_code\nMurid Workflow,0000000901,RPL,X-1\n");
        file_put_contents($teacherCsv, "name,nip\nGuru Workflow,0000000902\n");

        try {
            $this->browse(function (Browser $browser) use ($adminPassword, $semester, $studentCsv, $teacherCsv): void {
                $browser->visit('/admin/login')->type('#username', 'workflowadmin')->type('#password', $adminPassword)
                    ->press('Masuk')->waitForLocation('/admin')
                    ->select('#theme-select', 'light')
                    ->screenshot('rd03-admin-dashboard-light')
                    ->visit('/admin/confirm-password')->type('#current-password', $adminPassword)
                    ->press('Konfirmasi')->waitForLocation('/admin');

                $browser->visit('/admin/imports')->select('#import-semester', $semester->id)->attach('#import-file', $studentCsv)
                    ->waitForText('Berkas siap diperiksa:', 10)->press('Pratinjau berkas')->waitForText('Pratinjau batch', 15)
                    ->assertSee('Tidak ada kesalahan')->screenshot('rd03-admin-import-preview-light')
                    ->press('Konfirmasi dan buat 1 akun')->waitForText('Import selesai.', 15);

                $browser->visit('/admin/imports')->select('#import-type', 'teacher')->attach('#import-file', $teacherCsv)
                    ->waitForText('Berkas siap diperiksa:', 10)->press('Pratinjau berkas')->waitForText('Pratinjau batch', 15)
                    ->assertSee('Tidak ada kesalahan')->press('Konfirmasi dan buat 1 akun')->waitForText('Import selesai.', 15);

                $browser->visit('/admin/point-types')->type('#point-code', 'WF01')->select('#point-category', 'violation')
                    ->type('#point-name', 'Catatan Workflow')->type('#point-value', '5')->type('#point-description', 'Jenis poin untuk pemeriksaan workflow sekolah.')
                    ->press('Tambah jenis poin')->waitForText('Jenis poin dibuat pada versi katalog 1.')
                    ->screenshot('rd03-admin-point-catalog-light');

                $browser->visit('/admin/academic')->press('Buka semester')->waitForText('Semester dibuka. Penempatan siswa telah diperiksa.')
                    ->screenshot('rd03-admin-academic-light');
                $student = Student::where('nisn', '0000000901')->firstOrFail();
                $teacher = Teacher::where('nip', '0000000902')->firstOrFail();
                $version = PointTypeVersion::whereHas('pointType', fn ($query) => $query->where('code', 'WF01'))->firstOrFail();

                $browser->visit('/admin/students/'.$student->id)->press('Tampilkan password sementara')
                    ->waitFor('[data-credential-display]', 10);
                $studentTemporaryPassword = $browser->script('return document.querySelector("[data-credential-display]").textContent.trim();')[0];
                self::assertIsString($studentTemporaryPassword);
                self::assertNotSame('', $studentTemporaryPassword);
                $studentPassword = 'Synthetic workflow student phrase';
                $studentActivation = <<<'JS'
                    const temporaryPassword = __TEMPORARY_PASSWORD__;
                    window.__civoraStudentActivation = {done: false};
                    (async () => {
                        const request = async (path, body, token = null) => fetch(path, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', ...(token ? {Authorization: `Bearer ${token}`} : {})},
                            body: JSON.stringify(body),
                        });
                        const login = await request('/api/v1/auth/student/login', {nisn: '0000000901', password: temporaryPassword});
                        const initial = await login.json();
                        const activation = await request('/api/v1/auth/change-password', {
                            current_password: temporaryPassword,
                            password: 'Synthetic workflow student phrase',
                            password_confirmation: 'Synthetic workflow student phrase',
                        }, initial.data?.token);
                        window.__civoraStudentActivation = {done: true, statuses: [login.status, activation.status]};
                    })().catch(error => window.__civoraStudentActivation = {done: true, error: String(error)});
                    JS;
                $temporaryPasswordJson = json_encode($studentTemporaryPassword, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                $browser->script(str_replace('__TEMPORARY_PASSWORD__', $temporaryPasswordJson, $studentActivation));
                $browser->waitUsing(15, 100, fn () => $browser->script('return window.__civoraStudentActivation?.done === true;')[0] === true);
                $studentActivationResult = $browser->script('return window.__civoraStudentActivation;')[0];
                self::assertSame([200, 200], $studentActivationResult['statuses'] ?? [], json_encode($studentActivationResult));
                self::assertSame('active', $student->user->fresh()->account_status);
                self::assertSame(0, TemporaryCredential::where('user_id', $student->user_id)->count());

                $browser->visit('/admin/teachers')->screenshot('rd03-admin-teachers-light');
                $browser->script("Array.from(document.querySelectorAll('tbody tr')).find(row => row.textContent.includes('Guru Workflow'))?.querySelector('button:nth-of-type(2)')?.click();");
                $browser->waitForText('Target pembukaan password sementara: '.$teacher->id, 10)->press('Tampilkan password sementara')->waitForText('Password sementara guru', 10);
                $temporaryPassword = $browser->script("return document.querySelector('section[aria-labelledby=teachers-list-title] + section code, code')?.textContent.trim();")[0];
                self::assertIsString($temporaryPassword);
                self::assertNotSame('', $temporaryPassword);

                $payload = [
                    'temporaryPassword' => $temporaryPassword,
                    'teacherNip' => '0000000902',
                    'newPassword' => 'Synthetic workflow teacher phrase',
                    'studentNisn' => '0000000901',
                    'studentPassword' => $studentPassword,
                    'studentId' => $student->id,
                    'pointTypeVersionId' => $version->id,
                ];
                $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                $workflowScript = <<<'JS'
                    const input = __PAYLOAD__;
                    window.__civoraWorkflow = {done: false};
                    (async () => {
                        try {
                            const request = async (path, options = {}) => {
                                const {token, headers, body, ...rest} = options;
                                return fetch(path, {headers: {'Content-Type': 'application/json', 'Accept': 'application/json', ...(token ? {Authorization: `Bearer ${token}`} : {}), ...headers}, ...rest, body: body ? JSON.stringify(body) : undefined});
                            };
                            const login = await request('/api/v1/auth/teacher/login', {method: 'POST', body: {identifier: input.teacherNip, password: input.temporaryPassword}});
                            const initial = await login.json();
                            const activation = await request('/api/v1/auth/change-password', {method: 'POST', token: initial.data?.token, body: {current_password: input.temporaryPassword, password: input.newPassword, password_confirmation: input.newPassword}});
                            const activated = await activation.json();
                            const activeLogin = await request('/api/v1/auth/teacher/login', {method: 'POST', body: {identifier: input.teacherNip, password: input.newPassword}});
                            const active = await activeLogin.json();
                            const selectionResponse = await request('/api/v1/teacher/student-selections', {method: 'POST', token: active.data?.token, body: {student_id: input.studentId}});
                            const selection = await selectionResponse.json();
                            const recordResponse = await request('/api/v1/teacher/records', {method: 'POST', token: active.data?.token, headers: {'Idempotency-Key': 'workflow-record-0001'}, body: {selection_id: selection.data?.id, point_type_version_id: input.pointTypeVersionId, description: 'Catatan sintetis dari API browser workflow.'}});
                            const record = await recordResponse.json();
                            const studentLoginResponse = await request('/api/v1/auth/student/login', {method: 'POST', body: {nisn: input.studentNisn, password: input.studentPassword}});
                            const studentLogin = await studentLoginResponse.json();
                            const reviewResponse = await request(`/api/v1/student/records/${record.data?.id}/objections`, {method: 'POST', token: studentLogin.data?.token, body: {reason: 'Mohon periksa catatan poin ini.'}});
                            const review = await reviewResponse.json();
                            window.__civoraWorkflow = {done: true, statuses: [login.status, activation.status, activeLogin.status, selectionResponse.status, recordResponse.status, studentLoginResponse.status, reviewResponse.status], error: initial.error ?? activated.error ?? active.error ?? selection.error ?? record.error ?? studentLogin.error ?? review.error, recordId: record.data?.id, reviewId: review.data?.id};
                        } catch (error) {
                            window.__civoraWorkflow = {done: true, error: String(error)};
                        }
                    })();
                    JS;
                $browser->script(str_replace('__PAYLOAD__', $json, $workflowScript));
                $browser->waitUsing(30, 100, fn () => $browser->script('return window.__civoraWorkflow?.done === true;')[0] === true);
                $workflow = $browser->script('return window.__civoraWorkflow;')[0];
                self::assertSame([200, 200, 200, 201, 201, 200, 201], $workflow['statuses'] ?? [], json_encode($workflow));
                self::assertNotEmpty($workflow['recordId'] ?? null);
                self::assertNotEmpty($workflow['reviewId'] ?? null);

                $browser->visit('/admin/reviews')->assertSee('Keberatan siswa')->clickLink('Periksa catatan')
                    ->waitForText('Alasan dan pemohon', 10)->assertSee('Keberatan siswa')->select('#review-point-type', $version->id)
                    ->clear('#review-description')->type('#review-description', 'Keterangan hasil pemeriksaan workflow.')
                    ->type('#review-decision-reason', 'Catatan diperbaiki melalui pemeriksaan browser.')
                    ->press('Simpan keputusan')->waitForText('Pemeriksaan disetujui.', 10);
                $browser->visit('/admin/reports')->select('#report-semester', $semester->id)->press('Tampilkan laporan')
                    ->waitForText('Total pelanggaran berlaku', 10)->assertSee('5')->assertSee('Catatan Workflow')
                    ->screenshot('rd03-admin-reports-light');
            });
        } finally {
            @unlink($studentCsv);
            @unlink($teacherCsv);
        }
    }
}
