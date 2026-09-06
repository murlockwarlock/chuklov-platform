<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_eval_cases', function (Blueprint $table): void {
            $table->string('source_key', 120)->nullable()->after('name');
            $table->unique(['organization_id', 'eval_suite_id', 'source_key'], 'ai_eval_cases_source_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ai_eval_cases', function (Blueprint $table): void {
            $table->dropUnique('ai_eval_cases_source_key_unique');
            $table->dropColumn('source_key');
        });
    }
};
