<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Models\BroadcastBatch;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Broadcasts\Jobs\ProcessBroadcastBatch;
use Illuminate\Support\Facades\DB;

final class ScheduleBroadcastWork
{
    /** @return array{campaigns: int, batches: int} */
    public function handle(): array
    {
        $campaigns = 0;
        $batches = 0;

        while (true) {
            $claimed = DB::transaction(function (): ?BroadcastCampaign {
                $campaign = BroadcastCampaign::query()->where('state', BroadcastCampaignState::Scheduled->value)->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
                if ($campaign === null) {
                    return null;
                }
                $campaign->forceFill(['state' => BroadcastCampaignState::Dispatching, 'dispatch_started_at' => now()])->save();

                return $campaign->refresh();
            });

            if ($claimed === null) {
                break;
            }

            try {
                $batchIds = BroadcastBatch::query()->where('organization_id', $claimed->organization_id)->where('campaign_id', $claimed->getKey())->where('state', 'pending')->orderBy('sequence')->pluck('id');
                foreach ($batchIds as $batchId) {
                    ProcessBroadcastBatch::dispatch((int) $claimed->organization_id, (int) $batchId)->afterCommit();
                    $batches++;
                }
                $campaigns++;
                if ($batchIds->isEmpty()) {
                    $this->completeEmpty($claimed);
                }
            } catch (\Throwable) {
                DB::transaction(function () use ($claimed): void {
                    BroadcastCampaign::query()->where('organization_id', $claimed->organization_id)->whereKey($claimed->getKey())->where('state', BroadcastCampaignState::Dispatching->value)->update(['state' => BroadcastCampaignState::Scheduled->value, 'dispatch_started_at' => null]);
                });
            }
        }

        return ['campaigns' => $campaigns, 'batches' => $batches];
    }

    private function completeEmpty(BroadcastCampaign $campaign): void
    {
        BroadcastCampaign::query()->where('organization_id', $campaign->organization_id)->whereKey($campaign->getKey())->where('state', BroadcastCampaignState::Dispatching->value)->update(['state' => BroadcastCampaignState::Completed->value, 'completed_at' => now()]);
    }
}
