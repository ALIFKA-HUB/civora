<?php

namespace Tests\Browser;

use App\Models\LoginIdentifier;
use App\Models\User;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class AdminShellTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_admin_can_sign_in_and_use_the_responsive_theme_shell(): void
    {
        $admin = User::create([
            'role' => 'admin',
            'name' => 'Synthetic Browser Admin',
            'password_hash' => 'Synthetic browser admin passphrase',
            'account_status' => 'active',
            'must_change_password' => false,
        ]);
        LoginIdentifier::create([
            'user_id' => $admin->id,
            'role' => 'admin',
            'kind' => 'username',
            'normalized_value' => 'browseradmin',
        ]);

        $this->browse(function (Browser $browser): void {
            $browser->resize(1440, 1000)
                ->visit('/admin/login')
                ->keys('#username', 'browseradmin', WebDriverKeys::TAB)
                ->assertFocused('#password')
                ->type('#password', 'Synthetic browser admin passphrase')
                ->press('Masuk')
                ->waitForLocation('/admin')
                ->assertPathIs('/admin')
                ->assertSee('Dashboard')
                ->assertSee('Pemeriksaan menunggu')
                ->assertSee('Synthetic Browser Admin')
                ->assertDontSee('Synthetic browser admin passphrase')
                ->select('#theme-select', 'light')
                ->screenshot('admin-shell-desktop-light');

            $this->assertThemeContrast($browser);

            $browser->select('#theme-select', 'dark');

            self::assertSame('dark', $browser->script('return document.documentElement.dataset.theme;')[0]);
            $this->assertThemeContrast($browser);

            $browser->select('#theme-select', 'system');

            self::assertSame('system', $browser->script('return document.documentElement.dataset.theme;')[0]);
            self::assertTrue($browser->script("return window.matchMedia('(prefers-color-scheme: dark)').matches;")[0]);
            self::assertSame('#101919', $browser->script("return getComputedStyle(document.documentElement).getPropertyValue('--background').trim();")[0]);
            $this->assertThemeContrast($browser);

            $browser
                ->resize(320, 800)
                ->assertPresent('button[aria-label="Buka navigasi"]')
                ->click('button[aria-label="Buka navigasi"]')
                ->assertVisible('aside[aria-label="Navigasi utama"]')
                ->screenshot('admin-shell-mobile');

            $overflow = $browser->script('return document.documentElement.scrollWidth > window.innerWidth;')[0];
            self::assertFalse($overflow, 'The dashboard must fit a 320px viewport without horizontal page overflow.');

            $browser->keys('a[aria-current="page"]', WebDriverKeys::ESCAPE)
                ->assertMissing('aside[aria-label="Navigasi utama"]');
            foreach ([320, 768, 1024, 1440] as $width) {
                $browser->resize($width, 900);
                $overflow = $browser->script('return document.documentElement.scrollWidth > window.innerWidth;')[0];
                self::assertFalse($overflow, "The dashboard must fit a {$width}px viewport without horizontal page overflow.");
            }

            $browser->storeConsoleLog('admin-shell');
        });
    }

    private function assertThemeContrast(Browser $browser): void
    {
        $ratios = $browser->script(<<<'JS'
            const root = getComputedStyle(document.documentElement);
            const rgb = (token) => {
                const value = root.getPropertyValue(token).trim();
                const hex = value.startsWith('#') && value.length === 4
                    ? [...value.slice(1)].map((channel) => channel + channel).join('')
                    : value.slice(1);
                const channels = value.startsWith('#')
                    ? [0, 2, 4].map((offset) => parseInt(hex.slice(offset, offset + 2), 16))
                    : value.match(/[\d.]+/g).slice(0, 3).map(Number);
                return channels.map((channel) => channel / 255)
                    .map((channel) => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4);
            };
            const luminance = (channels) => channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
            const contrast = (first, second) => {
                const values = [luminance(rgb(first)), luminance(rgb(second))].sort((a, b) => b - a);
                return (values[0] + 0.05) / (values[1] + 0.05);
            };
            return [contrast('--text-main', '--background'), contrast('--text-secondary', '--background'), contrast('--on-primary', '--primary')];
            JS)[0];

        foreach ($ratios as $ratio) {
            self::assertGreaterThanOrEqual(4.5, $ratio, 'Theme text contrast must meet WCAG AA for normal-sized text.');
        }
    }
}
