<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Scenarios\Domain\Enums\ScenarioDeliveryStatus;
use App\Modules\Scenarios\Domain\Models\ScenarioDelivery;
use App\Modules\Scenarios\Domain\Models\ScenarioDeliveryAttempt;
use Illuminate\Support\Facades\DB;

final class RecordScenarioDeliveryPresentation
{
    public function handle(ScenarioDelivery $claimed, int $templateVersionId): bool
    {
        return DB::transaction(function () use ($claimed, $templateVersionId): bool {
            $current = ScenarioDelivery::query()->where('organization_id', $claimed->organization_id)
                ->whereKey($claimed->getKey())->lockForUpdate()->first();
            if ($current === null || $current->status !== ScenarioDeliveryStatus::Processing
                || $current->attempt_count !== $claimed->attempt_count
                || (string) $current->processing_started_at !== (string) $claimed->processing_started_at) {
                return false;
            }
            $attempt = ScenarioDeliveryAttempt::query()->where('organization_id', $current->organization_id)
                ->where('scenario_delivery_id', $current->getKey())->where('attempt_number', $current->attempt_count)
                ->where('outcome', NotificationDeliveryOutcome::Unknown->value)->lockForUpdate()->first();
            if ($attempt === null || ($attempt->template_version_id !== null && $attempt->template_version_id !== $templateVersionId)) {
                return false;
            }
            $attempt->forceFill(['template_version_id' => $templateVersionId])->save();

            return true;
        });
    }
}
