<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\StudentSelection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\FoundationFixture;
use Tests\TestCase;

class TimestampStorageTest extends TestCase
{
    use FoundationFixture, RefreshDatabase;

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_explicit_offset_and_automatic_model_timestamps_preserve_the_utc_instant(): void
    {
        $this->fixture();
        $instant = CarbonImmutable::parse('2026-08-01T09:00:00.123456+07:00');
        $this->travelTo($instant);
        $attributes = $this->rows['student_selections'];
        unset($attributes['id']);
        $attributes['expires_at'] = $instant;
        $selection = StudentSelection::create($attributes)->fresh();

        $this->assertSame('2026-08-01T02:00:00.123456Z', $selection->toArray()['expires_at']);
        $this->assertSame('2026-08-01T02:00:00.123456Z', $selection->toArray()['created_at']);
        $this->assertSame($instant->getTimestamp(), $selection->expires_at->getTimestamp());
        $this->assertSame('2026-08-01 02:00:00.123456', DB::scalar("SELECT to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') FROM student_selections WHERE id = ?", [$selection->id]));
    }

    public function test_native_sanctum_model_timestamps_and_expiry_preserve_offsets(): void
    {
        $user = User::factory()->create();
        $instant = CarbonImmutable::parse('2026-08-01T09:00:00+07:00');
        $this->travelTo($instant);
        $token = new PersonalAccessToken(['name' => 'synthetic-session', 'token' => str_repeat('a', 64), 'expires_at' => $instant]);
        $user->tokens()->save($token);
        $token->refresh();

        $this->assertSame('2026-08-01T02:00:00.000000Z', $token->toArray()['expires_at']);
        $this->assertSame('2026-08-01T02:00:00.000000Z', $token->toArray()['created_at']);
        $this->assertSame('2026-08-01T02:00:00.000000Z', $token->toArray()['updated_at']);
    }

    public function test_academic_dates_keep_their_calendar_date(): void
    {
        $year = AcademicYear::create([
            'name' => 'Synthetic date-only year',
            'start_date' => CarbonImmutable::parse('2026-07-01T00:00:00+07:00'),
            'end_date' => '2027-06-30',
        ])->fresh();

        $this->assertSame('2026-07-01', $year->start_date->format('Y-m-d'));
        $this->assertSame('2027-06-30', $year->end_date->format('Y-m-d'));
        $this->assertSame('2026-07-01', DB::table('academic_years')->where('id', $year->id)->value('start_date'));
    }

    public function test_server_uses_utc_and_school_display_defaults_to_jakarta(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Jakarta', config('app.school_timezone'));
    }
}
