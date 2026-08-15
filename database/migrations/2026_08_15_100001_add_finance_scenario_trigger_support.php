<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS scenario_rules_m5b_trigger_event_check');
        DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS scenario_actions_m5b_trigger_event_check');
        DB::statement(
            'ALTER TABLE scenario_rules ADD CONSTRAINT scenario_rules_m6_trigger_event_check '
            ."CHECK (trigger_event IN ('booking.completed', 'onboarding.started', 'finance.obligation.created'))",
        );
        DB::statement(
            'ALTER TABLE scenario_actions ADD CONSTRAINT scenario_actions_m6_trigger_event_check '
            ."CHECK (trigger_event IN ('booking.completed', 'onboarding.started', 'finance.obligation.created'))",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS scenario_actions_m6_trigger_event_check');
        DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS scenario_rules_m6_trigger_event_check');
        DB::statement(
            'ALTER TABLE scenario_rules ADD CONSTRAINT scenario_rules_m5b_trigger_event_check '
            ."CHECK (trigger_event IN ('booking.completed', 'onboarding.started'))",
        );
        DB::statement(
            'ALTER TABLE scenario_actions ADD CONSTRAINT scenario_actions_m5b_trigger_event_check '
            ."CHECK (trigger_event IN ('booking.completed', 'onboarding.started'))",
        );
    }
};
