<?php

use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $portalSource = ['type' => 'booking.source', 'operator' => 'equals', 'value' => 'portal'];

        ScenarioRule::query()
            ->whereIn('rule_key', [
                'booking-created-client-en',
                'booking-created-client-ru',
                'booking-created-specialist',
                'booking-created-specialist-database',
                'booking-home-visit-review-database',
            ])
            ->where('trigger_event', ScenarioEventType::BookingCreated->value)
            ->where('is_enabled', true)
            ->whereNull('created_by_user_id')
            ->whereNull('updated_by_user_id')
            ->get()
            ->each(function (ScenarioRule $rule) use ($portalSource): void {
                $legacyConditions = match ($rule->rule_key) {
                    'booking-created-client-en', 'booking-created-client-ru' => [[
                        'type' => 'client.language',
                        'operator' => 'equals',
                        'value' => str_ends_with($rule->rule_key, '-en') ? 'en' : 'ru',
                    ]],
                    'booking-created-specialist-database' => [[
                        'type' => 'booking.status',
                        'operator' => 'equals',
                        'value' => 'requested',
                    ]],
                    'booking-home-visit-review-database' => [[
                        'type' => 'booking.status',
                        'operator' => 'equals',
                        'value' => 'pending_review',
                    ]],
                    'booking-created-specialist' => [],
                    default => null,
                };

                if ($legacyConditions === null || $rule->conditions !== $legacyConditions) {
                    return;
                }

                $rule->forceFill([
                    'conditions' => [$portalSource, ...$legacyConditions],
                    'version' => $rule->version + 1,
                ])->save();
            });
    }

    public function down(): void {}
};
