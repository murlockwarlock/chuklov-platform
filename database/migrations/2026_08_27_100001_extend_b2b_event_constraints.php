<?php

use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
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
            'ALTER TABLE integration_events ADD CONSTRAINT b2b_integration_events_type_ck CHECK (event_type IN ('
            .$this->quotedValues($this->integrationEventTypes()).'))',
        );

        DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS scenario_rules_m5b_trigger_event_check');
        DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS scenario_rules_m6_trigger_event_check');
        DB::statement(
            'ALTER TABLE scenario_rules ADD CONSTRAINT b2b_scenario_rules_event_ck CHECK (trigger_event IN ('
            .$this->quotedValues($this->scenarioEventTypes()).'))',
        );

        DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS scenario_actions_m5b_trigger_event_check');
        DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS scenario_actions_m6_trigger_event_check');
        DB::statement(
            'ALTER TABLE scenario_actions ADD CONSTRAINT b2b_scenario_actions_event_ck CHECK (trigger_event IN ('
            .$this->quotedValues($this->scenarioEventTypes()).'))',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE scenario_actions DROP CONSTRAINT IF EXISTS b2b_scenario_actions_event_ck');
        DB::statement(
            'ALTER TABLE scenario_actions ADD CONSTRAINT scenario_actions_m6_trigger_event_check CHECK (trigger_event IN ('
            .$this->quotedValues($this->legacyScenarioEventTypes()).'))',
        );
        DB::statement('ALTER TABLE scenario_rules DROP CONSTRAINT IF EXISTS b2b_scenario_rules_event_ck');
        DB::statement(
            'ALTER TABLE scenario_rules ADD CONSTRAINT scenario_rules_m6_trigger_event_check CHECK (trigger_event IN ('
            .$this->quotedValues($this->legacyScenarioEventTypes()).'))',
        );
        DB::statement('ALTER TABLE integration_events DROP CONSTRAINT IF EXISTS b2b_integration_events_type_ck');
        DB::statement(
            'ALTER TABLE integration_events ADD CONSTRAINT integration_events_type_check CHECK (event_type IN ('
            .$this->quotedValues($this->legacyIntegrationEventTypes()).'))',
        );
    }

    /** @return list<string> */
    private function scenarioEventTypes(): array
    {
        return array_map(
            static fn (ScenarioEventType $event): string => $event->value,
            ScenarioEventType::cases(),
        );
    }

    /** @return list<string> */
    private function legacyScenarioEventTypes(): array
    {
        return array_slice($this->scenarioEventTypes(), 0, 5);
    }

    /** @return list<string> */
    private function integrationEventTypes(): array
    {
        return array_map(
            static fn (IntegrationEventType $event): string => $event->value,
            IntegrationEventType::cases(),
        );
    }

    /** @return list<string> */
    private function legacyIntegrationEventTypes(): array
    {
        return ['finance.obligation.settled'];
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
