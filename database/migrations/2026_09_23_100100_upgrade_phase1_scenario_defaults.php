<?php

use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ScenarioRule::query()
            ->where('rule_key', 'like', 'booking-completed-feedback-%')
            ->where('trigger_event', ScenarioEventType::BookingCompleted->value)
            ->where('is_enabled', true)
            ->whereNull('created_by_user_id')
            ->whereNull('updated_by_user_id')
            ->get()
            ->each(function (ScenarioRule $rule): void {
                if ($rule->delay_value !== 0
                    || $rule->delay_unit->value !== 'minutes'
                    || $rule->conditions !== [['type' => 'client.language', 'operator' => 'equals', 'value' => str_ends_with($rule->rule_key, '-en') ? 'en' : 'ru']]) {
                    return;
                }

                $rule->forceFill([
                    'delay_value' => 2,
                    'delay_unit' => 'hours',
                    'version' => $rule->version + 1,
                ])->save();
            });

        ScenarioRule::query()
            ->where('rule_key', 'like', 'post-session-follow-up-72h-%')
            ->where('trigger_event', ScenarioEventType::BookingCompleted->value)
            ->where('is_enabled', true)
            ->whereNull('created_by_user_id')
            ->whereNull('updated_by_user_id')
            ->get()
            ->each(function (ScenarioRule $rule): void {
                $locale = str_ends_with($rule->rule_key, '-en') ? 'en' : 'ru';
                $legacyConditions = [
                    ['type' => 'client.language', 'operator' => 'equals', 'value' => $locale],
                    ['type' => 'booking.status', 'operator' => 'equals', 'value' => 'completed'],
                ];

                if ($rule->conditions !== $legacyConditions) {
                    return;
                }

                $rule->forceFill([
                    'conditions' => [...$legacyConditions, ['type' => 'booking.has_qualifying_next_booking', 'operator' => 'equals', 'value' => false]],
                    'version' => $rule->version + 1,
                ])->save();
            });
    }

    public function down(): void {}
};
