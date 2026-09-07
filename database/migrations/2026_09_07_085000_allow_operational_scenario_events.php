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

        foreach (['b2b_scenario_rules_event_ck', 'scenario_rules_m6_trigger_event_check', 'booking_scenario_rules_event_ck'] as $constraint) {
            DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS '.$constraint);
        }
        foreach (['b2b_scenario_actions_event_ck', 'scenario_actions_m6_trigger_event_check', 'booking_scenario_actions_event_ck'] as $constraint) {
            DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS '.$constraint);
        }

        DB::statement(
            'ALTER TABLE scenario_rules ADD CONSTRAINT operational_scenario_rules_event_ck CHECK (trigger_event IN ('
            .$this->quotedValues($this->scenarioEventTypes()).'))',
        );
        DB::statement(
            'ALTER TABLE scenario_actions ADD CONSTRAINT operational_scenario_actions_event_ck CHECK (trigger_event IN ('
            .$this->quotedValues($this->scenarioEventTypes()).'))',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS operational_scenario_actions_event_ck');
        DB::statement(
            'ALTER TABLE scenario_actions ADD CONSTRAINT booking_scenario_actions_event_ck CHECK (trigger_event IN ('
            .$this->quotedValues($this->previousScenarioEventTypes()).'))',
        );
        DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS operational_scenario_rules_event_ck');
        DB::statement(
            'ALTER TABLE scenario_rules ADD CONSTRAINT booking_scenario_rules_event_ck CHECK (trigger_event IN ('
            .$this->quotedValues($this->previousScenarioEventTypes()).'))',
        );
    }

    private function scenarioEventTypes(): array
    {
        return [
            ...$this->previousScenarioEventTypes(),
            'booking.rejected',
            'companion.requested_specialist',
            'companion.fallback_failed',
            'broadcast.delivery_failed',
            'feedback.submitted',
            'referral.payout.requested',
            'referral.payout.status_changed',
            'booking.home_visit.changed',
            'ai.evaluation.failed',
            'referral.link.visited',
            'payment.provider.event.prepared',
        ];
    }

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
            'booking.created',
            'booking.rescheduled',
            'booking.cancelled',
        ];
    }

    private function quotedValues(array $values): string
    {
        return implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values,
        ));
    }
};
