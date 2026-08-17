<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('retrieval_embedding_reserved_cost_minor_units')->default(0);
            $table->date('retrieval_embedding_usage_date')->nullable();
            $table->string('retrieval_embedding_budget_status', 32)->default('none');
            $table->bigInteger('retrieval_embedding_settled_cost_minor_units')->nullable();
            $table->json('retrieval_embedding_pricing_snapshot')->nullable();
            $table->index(['organization_id', 'retrieval_embedding_budget_status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS ai_runs_status_check');
            DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('preparing', 'queued', 'running', 'succeeded', 'failed', 'cancelled', 'timed_out', 'invalid_output'))");
            DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_retrieval_embedding_budget_status_check CHECK (retrieval_embedding_budget_status IN ('none', 'reserved', 'settled', 'released', 'conservatively_charged'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS ai_runs_retrieval_embedding_budget_status_check');
            DB::statement('ALTER TABLE ai_runs DROP CONSTRAINT IF EXISTS ai_runs_status_check');
            DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'cancelled', 'timed_out', 'invalid_output'))");
        }

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'retrieval_embedding_budget_status']);
            $table->dropColumn([
                'retrieval_embedding_reserved_cost_minor_units',
                'retrieval_embedding_usage_date',
                'retrieval_embedding_budget_status',
                'retrieval_embedding_settled_cost_minor_units',
                'retrieval_embedding_pricing_snapshot',
            ]);
        });
    }
};
