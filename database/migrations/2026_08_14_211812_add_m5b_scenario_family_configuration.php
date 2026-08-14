<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenario_rules', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_occurrences')->default(1);
            $table->unsignedInteger('repeat_interval_value')->nullable();
            $table->string('repeat_interval_unit', 16)->nullable();
        });

        Schema::table('scenario_actions', function (Blueprint $table): void {
            $table->unsignedInteger('sequence_number')->default(1);
            $table->unsignedSmallInteger('max_occurrences')->default(1);
            $table->unsignedInteger('repeat_interval_value')->nullable();
            $table->string('repeat_interval_unit', 16)->nullable();
            $table->index(
                ['organization_id', 'scenario_event_id', 'scenario_rule_id', 'sequence_number'],
                'scenario_actions_event_rule_sequence_index',
            );
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE scenario_rules ADD CONSTRAINT scenario_rules_m5b_trigger_event_check '
            ."CHECK (trigger_event IN ('booking.completed', 'onboarding.started'))",
        );
        DB::statement(
            'ALTER TABLE scenario_rules ADD CONSTRAINT scenario_rules_m5b_repeat_check '
            ."CHECK (max_occurrences BETWEEN 1 AND 100 AND ((max_occurrences = 1 AND repeat_interval_value IS NULL AND repeat_interval_unit IS NULL) OR (max_occurrences > 1 AND repeat_interval_value > 0 AND repeat_interval_unit IN ('minutes', 'hours', 'days'))))",
        );
        DB::statement(
            'ALTER TABLE scenario_actions ADD CONSTRAINT scenario_actions_m5b_trigger_event_check '
            ."CHECK (trigger_event IN ('booking.completed', 'onboarding.started'))",
        );
        DB::statement(
            'ALTER TABLE scenario_actions ADD CONSTRAINT scenario_actions_m5b_repeat_check '
            ."CHECK (sequence_number BETWEEN 1 AND max_occurrences AND max_occurrences BETWEEN 1 AND 100 AND ((max_occurrences = 1 AND repeat_interval_value IS NULL AND repeat_interval_unit IS NULL) OR (max_occurrences > 1 AND repeat_interval_value > 0 AND repeat_interval_unit IN ('minutes', 'hours', 'days'))))",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS scenario_actions_m5b_repeat_check');
            DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS scenario_actions_m5b_trigger_event_check');
            DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS scenario_rules_m5b_repeat_check');
            DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS scenario_rules_m5b_trigger_event_check');
        }

        Schema::table('scenario_actions', function (Blueprint $table): void {
            $table->dropIndex('scenario_actions_event_rule_sequence_index');
            $table->dropColumn([
                'sequence_number',
                'max_occurrences',
                'repeat_interval_value',
                'repeat_interval_unit',
            ]);
        });

        Schema::table('scenario_rules', function (Blueprint $table): void {
            $table->dropColumn([
                'max_occurrences',
                'repeat_interval_value',
                'repeat_interval_unit',
            ]);
        });
    }
};
