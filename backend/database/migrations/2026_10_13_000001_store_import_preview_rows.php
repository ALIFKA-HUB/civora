<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE import_batches ADD COLUMN parsed_rows jsonb NOT NULL DEFAULT '[]'::jsonb");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE import_batches DROP COLUMN parsed_rows');
    }
};
