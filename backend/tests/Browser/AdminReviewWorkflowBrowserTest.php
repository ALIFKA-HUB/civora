<?php

namespace Tests\Browser;

use App\Actions\Reviews\SubmitReviewRequest;
use App\Models\Attachment;
use App\Models\PointRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\FoundationFixture;

class AdminReviewWorkflowBrowserTest extends DuskTestCase
{
    use DatabaseMigrations, FoundationFixture;

    public function test_admin_can_review_a_private_image_in_light_and_dark_themes(): void
    {
        $this->fixture();
        DB::table('review_requests')->delete();
        $admin = User::findOrFail($this->rows['users']['id']);
        $admin->update([
            'password_hash' => Hash::make('Synthetic browser review admin phrase'),
            'account_status' => 'active',
            'must_change_password' => false,
        ]);
        DB::table('login_identifiers')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'role' => 'admin',
            'kind' => 'username',
            'normalized_value' => 'reviewadmin',
        ]);
        $student = User::findOrFail(DB::table('students')->value('user_id'));
        $teacher = User::findOrFail(DB::table('teachers')->value('user_id'));
        $student->update(['account_status' => 'active', 'must_change_password' => false]);
        $teacher->update(['account_status' => 'active', 'must_change_password' => false]);
        $record = PointRecord::findOrFail($this->rows['point_records']['id']);
        $review = app(SubmitReviewRequest::class)->execute($student, $record, 'student_objection', 'Saya meminta pemeriksaan catatan ini.', []);
        $attachment = Attachment::findOrFail($this->rows['attachments']['id']);
        Storage::disk('local')->put($attachment->private_path, UploadedFile::fake()->image('review-evidence.png', 320, 200)->getContent());

        $this->browse(function (Browser $browser) use ($review): void {
            $browser->resize(1440, 1000)
                ->visit('/admin/login')
                ->type('#username', 'reviewadmin')
                ->type('#password', 'Synthetic browser review admin phrase')
                ->press('Masuk')
                ->waitForLocation('/admin')
                ->visit('/admin/confirm-password')
                ->type('#current-password', 'Synthetic browser review admin phrase')
                ->press('Konfirmasi')
                ->waitForLocation('/admin')
                ->select('#theme-select', 'light')
                ->visit('/admin/reviews/'.$review->id)
                ->assertSee('Detail pemeriksaan')
                ->assertSee('Alasan pemohon')
                ->assertSee('Sebelum pemeriksaan')
                ->assertSee('Sesudah keputusan')
                ->assertSee('Simpan keputusan')
                ->assertSee('Menunggu pemeriksaan')
                ->assertPresent('label[for="review-decision"]')
                ->assertPresent('label[for="review-decision-reason"]')
                ->assertPresent('img[alt="Bukti gambar privat untuk catatan"]');
            $imageState = $browser->script(<<<'JS'
                const image = document.querySelector('img[alt="Bukti gambar privat untuk catatan"]');
                const request = new XMLHttpRequest();
                request.open('GET', image.src, false);
                request.send();
                return {src: image.src, naturalWidth: image.naturalWidth, status: request.status, type: request.getResponseHeader('Content-Type')};
            JS);
            self::assertSame(200, $imageState[0]['status'], json_encode($imageState[0]));
            $browser->waitUsing(10, 100, fn () => $browser->script('return document.querySelector("img[alt=\"Bukti gambar privat untuk catatan\"]")?.naturalWidth > 0;')[0] === true);
            $browser
                ->screenshot('a04-review-detail-light');
            self::assertSame('#f7fafa', strtolower($browser->script("return getComputedStyle(document.documentElement).getPropertyValue('--background').trim();")[0]));

            $browser->select('#theme-select', 'dark')
                ->visit('/admin/reviews/'.$review->id)
                ->assertSee('Detail pemeriksaan')
                ->assertPresent('img[alt="Bukti gambar privat untuk catatan"]')
                ->waitUsing(10, 100, fn () => $browser->script('return document.querySelector("img[alt=\"Bukti gambar privat untuk catatan\"]")?.naturalWidth > 0;')[0] === true)
                ->screenshot('a04-review-detail-dark');
            self::assertSame('#101919', strtolower($browser->script("return getComputedStyle(document.documentElement).getPropertyValue('--background').trim();")[0]));
            self::assertTrue($browser->script('return document.querySelector("img[alt=\"Bukti gambar privat untuk catatan\"]")?.naturalWidth > 0;')[0]);
            $browser->storeConsoleLog('admin-review-workflow');
        });
    }
}
