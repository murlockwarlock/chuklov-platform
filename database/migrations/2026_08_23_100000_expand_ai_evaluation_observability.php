<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_eval_runs', function (Blueprint $table): void {
            $table->decimal('pass_percentage', 5, 2)->default(0);
            $table->unsignedBigInteger('total_latency_ms')->default(0);
            $table->unsignedInteger('average_latency_ms')->default(0);
            $table->unsignedBigInteger('total_prompt_tokens')->default(0);
            $table->unsignedBigInteger('total_completion_tokens')->default(0);
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('failover_count')->default(0);
            $table->unsignedInteger('execution_error_count')->default(0);
            $table->unsignedInteger('rag_failed_cases')->default(0);
            $table->unsignedInteger('human_reviewed_cases')->default(0);
            $table->bigInteger('estimated_cost_minor_units')->nullable();
            $table->bigInteger('provider_cost_minor_units')->nullable();
            $table->json('metrics_payload')->nullable();
            $table->json('provenance_snapshot')->nullable();
            $table->index(['organization_id', 'eval_suite_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_eval_runs ADD CONSTRAINT ai_eval_runs_pass_percentage_check CHECK (pass_percentage >= 0 AND pass_percentage <= 100)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_eval_runs DROP CONSTRAINT IF EXISTS ai_eval_runs_pass_percentage_check');
        }

        Schema::table('ai_eval_runs', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'eval_suite_id', 'created_at']);
            $table->dropColumn([
                'pass_percentage',
                'total_latency_ms',
                'average_latency_ms',
                'total_prompt_tokens',
                'total_completion_tokens',
                'retry_count',
                'failover_count',
                'execution_error_count',
                'rag_failed_cases',
                'human_reviewed_cases',
                'estimated_cost_minor_units',
                'provider_cost_minor_units',
                'metrics_payload',
                'provenance_snapshot',
            ]);
        });
    }
};
