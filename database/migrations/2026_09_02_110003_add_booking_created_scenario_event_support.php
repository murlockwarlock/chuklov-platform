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

        DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS booking_scenario_rules_event_ck');
        DB::statement(
            'ALTER TABLE scenario_rules ADD CONSTRAINT booking_scenario_rules_event_ck CHECK (trigger_event IN ('
            .$this->quotedValues($this->scenarioEventTypes()).'))',
        );
        DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS booking_scenario_actions_event_ck');
        DB::statement(
            'ALTER TABLE scenario_actions ADD CONSTRAINT booking_scenario_actions_event_ck CHECK (trigger_event IN ('
            .$this->quotedValues($this->scenarioEventTypes()).'))',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS booking_scenario_actions_event_ck');
        DB::statement(
            'ALTER TABLE scenario_actions ADD CONSTRAINT booking_scenario_actions_event_ck CHECK (trigger_event IN ('
            .$this->quotedValues($this->previousScenarioEventTypes()).'))',
        );
        DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS booking_scenario_rules_event_ck');
        DB::statement(
            'ALTER TABLE scenario_rules ADD CONSTRAINT booking_scenario_rules_event_ck CHECK (trigger_event IN ('
            .$this->quotedValues($this->previousScenarioEventTypes()).'))',
        );
    }

    /** @return list<string> */
    private function scenarioEventTypes(): array
    {
        return [...$this->previousScenarioEventTypes(), 'booking.created'];
    }

    /** @return list<string> */
    private function previousScenarioEventTypes(): array
    {
        return [
            'booking.completed',
            'onboarding.started',
            'finance.obligation.created',
            'survey.completed',
            'TEST_STAGNATION_DETECTED',
            'b2b.lead.submitted',
            'b2b.sales_call.ready',
            'booking.confirmed',
        ];
    }

    /** @param list<string> $values */
    private function quotedValues(array $values): string
    {
        return implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values,
        ));
    }
};
