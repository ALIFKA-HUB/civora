<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE report_exports
    ADD COLUMN generation_token uuid,
    ADD COLUMN generation_lease_until timestamptz
SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE report_exports
    DROP COLUMN generation_token,
    DROP COLUMN generation_lease_until
SQL);
    }
};
