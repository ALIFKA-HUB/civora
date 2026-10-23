<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class QrConcurrencyTest extends TestCase
{
    use DatabaseMigrations, FoundationFixture;

    public function test_two_teachers_cannot_redeem_the_same_qr(): void
    {
        $this->fixture();
        $this->freezeTime();
        $this->travelTo(now()->setDate(2026, 8, 1)->setTime(9, 0)->setTimezone('Asia/Jakarta'));
        $student = User::findOrFail(DB::table('students')->value('user_id'));
        $teacher = User::findOrFail(DB::table('teachers')->value('user_id'));
        $student->update(['account_status' => 'active', 'must_change_password' => false]);
        $teacher->update(['account_status' => 'active', 'must_change_password' => false]);
        $another = User::create(['role' => 'teacher', 'name' => 'Concurrent teacher', 'password_hash' => 'Synthetic password', 'account_status' => 'active', 'must_change_password' => false]);
        DB::table('teachers')->insert(['id' => (string) Str::uuid(), 'user_id' => $another->id, 'teacher_code' => 'G002']);
        $raw = $student->createToken('student-device')->plainTextToken;
        $qr = $this->withToken($raw)->postJson('/api/v1/student/qr')->assertOk()->json('data.token');

        $directory = base_path('../.runtime/qr-tests/'.bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        $configFile = $directory.'/config.php';
        file_put_contents($configFile, '<?php return '.var_export(config()->all(), true).';');
        $issuedAt = DB::table('qr_tokens')->where('token_hash', hash('sha256', $qr))->value('issued_at');
        $config = ['APP_CONFIG_CACHE' => $configFile, 'CIVORA_TEST_QR_TOKEN' => $qr, 'CIVORA_TEST_RELEASE_FILE' => $directory.'/release', 'CIVORA_TEST_NOW' => $issuedAt];
        $first = new Process([PHP_BINARY, base_path('tests/Support/qr-concurrency-probe.php'), $teacher->id, 'scan-one'], base_path(), $config);
        $second = new Process([PHP_BINARY, base_path('tests/Support/qr-concurrency-probe.php'), $another->id, 'scan-two'], base_path(), $config);
        $first->setTimeout(20);
        $second->setTimeout(20);
        try {
            $first->start();
            $second->start();
            file_put_contents($directory.'/release', 'go');
            $first->wait();
            $second->wait();
            self::assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            self::assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            self::assertEqualsCanonicalizing(['redeemed', 'conflict'], [$first->getOutput(), $second->getOutput()]);
            self::assertSame(1, DB::table('qr_redemptions')->where('qr_token_id', DB::table('qr_tokens')->where('token_hash', hash('sha256', $qr))->value('id'))->count());
        } finally {
            $first->stop();
            $second->stop();
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
