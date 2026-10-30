<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE feedback_submissions DROP CONSTRAINT IF EXISTS feedback_submissions_score_check');
        DB::statement('ALTER TABLE feedback_submissions ADD CONSTRAINT feedback_submissions_score_check CHECK (score BETWEEN 0 AND 10)');
    }

    public function down(): void
    {
        throw new LogicException('The feedback score range is retained by forward-only migrations.');
    }
};
