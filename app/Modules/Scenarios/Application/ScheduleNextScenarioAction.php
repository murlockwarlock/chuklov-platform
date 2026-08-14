<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioIdempotencyKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ScheduleNextScenarioAction
{
    public function handle(ScenarioAction $deliveredAction): void
    {
        $nextSequence = $deliveredAction->sequence_number + 1;

        if ($nextSequence > $deliveredAction->max_occurrences
            || $deliveredAction->repeat_interval_value === null
            || $deliveredAction->repeat_interval_unit === null) {
            return;
        }

        $rule = ScenarioRule::query()
            ->where('organization_id', $deliveredAction->organization_id)
            ->whereKey($deliveredAction->scenario_rule_id)
            ->lockForUpdate()
            ->first();

        if ($rule === null || ! $rule->is_enabled) {
            return;
        }

        $recipientKey = $deliveredAction->recipient_type.':'.(
            $deliveredAction->client_id ?? $deliveredAction->recipient_user_id
        );
        $materializationKey = ScenarioIdempotencyKey::materialization(
            organizationId: (int) $deliveredAction->organization_id,
            eventId: (int) $deliveredAction->scenario_event_id,
            ruleId: (int) $deliveredAction->scenario_rule_id,
            recipientKey: $recipientKey,
            sequenceNumber: $nextSequence,
        );
        $scheduledFor = CarbonImmutable::parse((string) ($deliveredAction->delivered_at ?? now()))
            ->utc()
            ->addSeconds($deliveredAction->repeat_interval_unit->toSeconds($deliveredAction->repeat_interval_value));
        $timestamp = now();

        DB::table('scenario_actions')->insertOrIgnore([
            'organization_id' => $deliveredAction->organization_id,
            'scenario_event_id' => $deliveredAction->scenario_event_id,
            'scenario_rule_id' => $deliveredAction->scenario_rule_id,
            'recipient_type' => $deliveredAction->recipient_type,
            'client_id' => $deliveredAction->client_id,
            'recipient_user_id' => $deliveredAction->recipient_user_id,
            'template_version_id' => $deliveredAction->template_version_id,
            'trigger_event' => $deliveredAction->trigger_event->value,
            'rule_version' => $deliveredAction->rule_version,
            'condition_snapshot' => json_encode($deliveredAction->condition_snapshot, JSON_THROW_ON_ERROR),
            'sequence_number' => $nextSequence,
            'max_occurrences' => $deliveredAction->max_occurrences,
            'repeat_interval_value' => $deliveredAction->repeat_interval_value,
            'repeat_interval_unit' => $deliveredAction->repeat_interval_unit->value,
            'purpose' => $deliveredAction->purpose->value,
            'channel_priority' => json_encode($deliveredAction->channel_priority, JSON_THROW_ON_ERROR),
            'render_context' => json_encode($deliveredAction->render_context, JSON_THROW_ON_ERROR),
            'materialization_key' => $materializationKey,
            'scheduled_for' => $scheduledFor,
            'status' => ScenarioActionStatus::Scheduled->value,
            'attempt_count' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $actionId = DB::table('scenario_actions')
            ->where('organization_id', $deliveredAction->organization_id)
            ->where('materialization_key', $materializationKey)
            ->value('id');

        if ($actionId === null) {
            return;
        }

        foreach ($deliveredAction->channel_priority as $priority => $channel) {
            DB::table('scenario_deliveries')->insertOrIgnore([
                'organization_id' => $deliveredAction->organization_id,
                'scenario_action_id' => $actionId,
                'channel' => $channel,
                'priority' => $priority + 1,
                'status' => 'pending',
                'idempotency_key' => ScenarioIdempotencyKey::delivery(
                    (int) $deliveredAction->organization_id,
                    (int) $actionId,
                    $channel,
                ),
                'attempt_count' => 0,
                'next_attempt_at' => $scheduledFor,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }
}
