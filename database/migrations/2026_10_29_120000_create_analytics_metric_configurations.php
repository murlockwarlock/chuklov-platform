<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_metric_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('stable_key', 120);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->string('output_unit', 20);
            $table->unsignedSmallInteger('decimal_precision')->default(2);
            $table->string('source_a_key', 120);
            $table->string('source_b_key', 120)->nullable();
            $table->string('aggregation', 30);
            $table->string('calculation', 40);
            $table->string('comparison', 30)->default('none');
            $table->unsignedBigInteger('comparison_metric_id')->nullable();
            $table->decimal('target_value', 24, 8)->nullable();
            $table->string('null_policy', 30)->default('do_not_calculate');
            $table->string('zero_policy', 30)->default('do_not_calculate');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'stable_key']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'active', 'sort_order']);
            $table->foreign(['organization_id', 'comparison_metric_id'])
                ->references(['organization_id', 'id'])
                ->on('analytics_metric_configurations')
                ->nullOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE analytics_metric_configurations ADD CONSTRAINT analytics_metric_configurations_shape_check CHECK (decimal_precision <= 3 AND version > 0 AND stable_key ~ '^[a-z][a-z0-9_]{1,119}$' AND (comparison <> 'metric' OR comparison_metric_id IS NOT NULL) AND (comparison <> 'target' OR target_value IS NOT NULL))");
        }

        $now = now();
        $organizations = DB::table('organizations')->pluck('id');
        foreach ($organizations as $organizationId) {
            DB::table('analytics_metric_configurations')->insertOrIgnore([
                [
                    'organization_id' => $organizationId,
                    'stable_key' => 'pain_reduction_percent',
                    'name' => 'Снижение боли, %',
                    'description' => 'Процентное снижение средней боли до и после сеанса.',
                    'active' => true,
                    'output_unit' => 'percent',
                    'decimal_precision' => 2,
                    'source_a_key' => 'pain.average_before',
                    'source_b_key' => 'pain.average_after',
                    'aggregation' => 'average',
                    'calculation' => 'reduction_percent',
                    'comparison' => 'none',
                    'null_policy' => 'do_not_calculate',
                    'zero_policy' => 'do_not_calculate',
                    'sort_order' => 10,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'organization_id' => $organizationId,
                    'stable_key' => 'ltv_change_percent',
                    'name' => 'Изменение LTV к предыдущему периоду, %',
                    'description' => 'Изменение денежного LTV новых клиентов относительно предыдущего сопоставимого периода.',
                    'active' => true,
                    'output_unit' => 'percent',
                    'decimal_precision' => 2,
                    'source_a_key' => 'finance.new_client_ltv',
                    'source_b_key' => null,
                    'aggregation' => 'average',
                    'calculation' => 'change_percent',
                    'comparison' => 'previous_period',
                    'null_policy' => 'do_not_calculate',
                    'zero_policy' => 'do_not_calculate',
                    'sort_order' => 20,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_metric_configurations');
    }
};
