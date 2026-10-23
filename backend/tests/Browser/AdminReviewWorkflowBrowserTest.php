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
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Laravel\Dusk\Keyboard;
use Laravel\Dusk\Browser;
use Facebook\WebDriver\WebDriverKeys;
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
            $browser->withKeyboard(fn (Keyboard $keyboard) => $keyboard->press(WebDriverKeys::TAB));
            $focusState = $browser->script("return {tag: document.activeElement.tagName, href: document.activeElement.getAttribute('href'), outline: getComputedStyle(document.activeElement).outlineStyle, width: getComputedStyle(document.activeElement).outlineWidth};")[0];
            self::assertSame('A', $focusState['tag']);
            self::assertSame('#main-content', $focusState['href']);
            self::assertSame('solid', $focusState['outline']);
            self::assertSame('3px', $focusState['width']);
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
            self::assertSame('#f5f5f6', strtolower($browser->script("return getComputedStyle(document.documentElement).getPropertyValue('--background').trim();")[0]));
            self::assertSame('#0b625a', strtolower($browser->script("return getComputedStyle(document.documentElement).getPropertyValue('--primary').trim();")[0]));

            $browser->select('#theme-select', 'dark')
                ->visit('/admin/reviews/'.$review->id)
                ->assertSee('Detail pemeriksaan')
                ->assertPresent('img[alt="Bukti gambar privat untuk catatan"]')
                ->waitUsing(10, 100, fn () => $browser->script('return document.querySelector("img[alt=\"Bukti gambar privat untuk catatan\"]")?.naturalWidth > 0;')[0] === true)
                ->screenshot('a04-review-detail-dark');
            self::assertSame('#141719', strtolower($browser->script("return getComputedStyle(document.documentElement).getPropertyValue('--background').trim();")[0]));
            self::assertSame('#e3e5e8', strtolower($browser->script("return getComputedStyle(document.documentElement).getPropertyValue('--button-primary').trim();")[0]));
            self::assertSame('#172225', strtolower($browser->script("return getComputedStyle(document.documentElement).getPropertyValue('--button-primary-text').trim();")[0]));
            self::assertTrue($browser->script('return document.querySelector("img[alt=\"Bukti gambar privat untuk catatan\"]")?.naturalWidth > 0;')[0]);

            $devTools = new ChromeDevToolsDriver($browser->driver);
            $devTools->execute('Emulation.setDeviceMetricsOverride', [
                'width' => 360,
                'height' => 800,
                'deviceScaleFactor' => 1,
                'mobile' => true,
            ]);
            $browser->assertSee('Detail pemeriksaan')
                ->assertPresent('button[aria-label="Buka navigasi"]');
            $mobileState = $browser->script('const name = document.querySelector("header span"); return {viewport: document.documentElement.clientWidth, page: document.documentElement.scrollWidth, nameVisible: !!name && getComputedStyle(name).display !== "none" && name.getBoundingClientRect().width > 0};')[0];
            self::assertSame(360, $mobileState['viewport']);
            self::assertLessThanOrEqual($mobileState['viewport'], $mobileState['page'], 'The admin page must not overflow horizontally at a narrow viewport.');
            self::assertTrue($mobileState['nameVisible'], 'The admin identity remains visible on a narrow viewport.');
            $browser->screenshot('a04-review-detail-mobile-dark');
            $devTools->execute('Emulation.setEmulatedMedia', [
                'features' => [['name' => 'prefers-reduced-motion', 'value' => 'reduce']],
            ]);
            $reducedMotionDuration = $browser->script('return parseFloat(getComputedStyle(document.querySelector("button")).transitionDuration) || 0;')[0];
            self::assertLessThanOrEqual(0.001, $reducedMotionDuration, 'Reduced motion shortens transitions for administrative controls.');
            $browser->click('button[aria-label="Buka navigasi"]')
                ->waitFor('aside[role="dialog"][aria-modal="true"]', 5);
            self::assertSame('Civora', $browser->script('return document.activeElement.textContent.trim();')[0]);
            $browser->withKeyboard(fn (Keyboard $keyboard) => $keyboard->press(WebDriverKeys::ESCAPE))
                ->waitUntilMissing('aside[role="dialog"][aria-modal="true"]', 5);
            self::assertSame('button', $browser->script('return document.activeElement.tagName.toLowerCase();')[0]);
            self::assertSame('false', $browser->script('return document.activeElement.getAttribute("aria-expanded");')[0]);
            $browser->storeConsoleLog('admin-review-workflow');
        });
    }
}
