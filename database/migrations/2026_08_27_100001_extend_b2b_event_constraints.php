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

        DB::statement('ALTER TABLE integration_events DROP CONSTRAINT IF EXISTS integration_events_type_check');
        DB::statement(
            "ALTER TABLE integration_events ADD CONSTRAINT b2b_integration_events_type_ck CHECK (event_type IN ('finance.obligation.settled', 'b2b.sales_call.provider_sync'))",
        );

        DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS scenario_rules_m5b_trigger_event_check');
        DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS scenario_rules_m6_trigger_event_check');
        DB::statement(
            "ALTER TABLE scenario_rules ADD CONSTRAINT b2b_scenario_rules_event_ck CHECK (trigger_event IN ('booking.completed', 'onboarding.started', 'finance.obligation.created', 'b2b.lead.submitted', 'b2b.sales_call.ready'))",
        );

        DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS scenario_actions_m5b_trigger_event_check');
        DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS scenario_actions_m6_trigger_event_check');
        DB::statement(
            "ALTER TABLE scenario_actions ADD CONSTRAINT b2b_scenario_actions_event_ck CHECK (trigger_event IN ('booking.completed', 'onboarding.started', 'finance.obligation.created', 'b2b.lead.submitted', 'b2b.sales_call.ready'))",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS b2b_scenario_actions_event_ck');
        DB::statement(
            "ALTER TABLE scenario_actions ADD CONSTRAINT scenario_actions_m6_trigger_event_check CHECK (trigger_event IN ('booking.completed', 'onboarding.started', 'finance.obligation.created'))",
        );
        DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS b2b_scenario_rules_event_ck');
        DB::statement(
            "ALTER TABLE scenario_rules ADD CONSTRAINT scenario_rules_m6_trigger_event_check CHECK (trigger_event IN ('booking.completed', 'onboarding.started', 'finance.obligation.created'))",
        );
        DB::statement('ALTER TABLE integration_events DROP CONSTRAINT IF EXISTS b2b_integration_events_type_ck');
        DB::statement("ALTER TABLE integration_events ADD CONSTRAINT integration_events_type_check CHECK (event_type = 'finance.obligation.settled')");
    }
};
