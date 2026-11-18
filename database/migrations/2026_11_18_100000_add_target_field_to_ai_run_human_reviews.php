<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ai_run_human_reviews', 'target_field')) {
            Schema::table('ai_run_human_reviews', function (Blueprint $table): void {
                $table->string('target_field', 64)->nullable()->after('safe_reason_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_run_human_reviews', 'target_field')) {
            Schema::table('ai_run_human_reviews', function (Blueprint $table): void {
                $table->dropColumn('target_field');
            });
        }
    }
};
