<?php

namespace Tests\Browser;

use App\Models\LoginIdentifier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class AdminPointRulesBrowserTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_admin_point_rules_pages_have_labeled_controls_in_both_themes(): void
    {
        $password = 'Synthetic browser administrator phrase';
        $admin = User::create([
            'role' => 'admin',
            'name' => 'Synthetic Point Rules Admin',
            'password_hash' => Hash::make($password),
            'account_status' => 'active',
            'must_change_password' => false,
        ]);
        LoginIdentifier::create(['user_id' => $admin->id, 'role' => 'admin', 'kind' => 'username', 'normalized_value' => 'poinadmin']);

        $this->browse(function (Browser $browser) use ($password): void {
            $browser->resize(1440, 1000)
                ->visit('/admin/login')
                ->type('#username', 'poinadmin')
                ->type('#password', $password)
                ->press('Masuk')
                ->waitForLocation('/admin')
                ->visit('/admin/confirm-password')
                ->type('#current-password', $password)
                ->press('Konfirmasi')
                ->waitForLocation('/admin')
                ->select('#theme-select', 'light')
                ->visit('/admin/point-types')
                ->assertSee('Daftar poin')
                ->assertPresent('label[for="point-code"]')
                ->assertPresent('label[for="point-value"]')
                ->assertPresent('table caption')
                ->screenshot('a03-point-types-light');
            self::assertSame('#f7fafa', strtolower($browser->script("return getComputedStyle(document.documentElement).getPropertyValue('--background').trim();")[0]));

            foreach ([
                ['/admin/point-types', 'Daftar poin', 'label[for="point-code"]'],
                ['/admin/thresholds', 'Ambang poin', 'label[for="threshold-semester"]'],
                ['/admin/follow-ups', 'Tindak lanjut', 'label[for="follow-up-alert"]'],
            ] as [$path, $heading, $label]) {
                $browser->visit($path)->assertSee($heading)->assertPresent($label);
            }

            $browser->select('#theme-select', 'dark')
                ->screenshot('a03-follow-ups-dark');
            self::assertSame('#101919', strtolower($browser->script("return getComputedStyle(document.documentElement).getPropertyValue('--background').trim();")[0]));

            foreach ([
                ['/admin/point-types', 'Daftar poin', 'label[for="point-code"]'],
                ['/admin/thresholds', 'Ambang poin', 'label[for="threshold-semester"]'],
                ['/admin/follow-ups', 'Tindak lanjut', 'label[for="follow-up-alert"]'],
            ] as [$path, $heading, $label]) {
                $browser->visit($path)->assertSee($heading)->assertPresent($label);
            }
            $browser->storeConsoleLog('admin-point-rules');
        });
    }
}
