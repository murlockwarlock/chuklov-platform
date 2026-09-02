<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Enums\BroadcastRecipientState;
use App\Modules\Broadcasts\Domain\Models\BroadcastAudienceSnapshot;
use App\Modules\Broadcasts\Domain\Models\BroadcastBatch;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Broadcasts\Domain\Models\BroadcastRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class MaterializeBroadcastAudience
{
    private const BATCH_SIZE = 100;

    public function __construct(private BroadcastSegmentQuery $segments, private BroadcastEligibilityPolicy $eligibility) {}

    public function handle(BroadcastCampaign $campaign): BroadcastAudienceSnapshot
    {
        return DB::transaction(function () use ($campaign): BroadcastAudienceSnapshot {
            $locked = BroadcastCampaign::query()->where('organization_id', $campaign->organization_id)->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->state !== BroadcastCampaignState::Draft) {
                throw ValidationException::withMessages(['campaign' => 'Список получателей можно зафиксировать только для черновика.']);
            }
            if ($locked->audience_snapshot_id !== null) {
                $existing = BroadcastAudienceSnapshot::query()->where('organization_id', $locked->organization_id)->findOrFail($locked->audience_snapshot_id);
                if ((int) $existing->draft_version !== (int) $locked->draft_version) {
                    throw ValidationException::withMessages(['campaign' => 'Список получателей устарел. Обновите рассылку и зафиксируйте его заново.']);
                }

                return $existing;
            }

            $version = (int) BroadcastAudienceSnapshot::query()->where('organization_id', $locked->organization_id)->where('campaign_id', $locked->getKey())->max('version') + 1;
            $snapshot = new BroadcastAudienceSnapshot;
            $snapshot->forceFill([
                'organization_id' => $locked->organization_id,
                'campaign_id' => $locked->getKey(),
                'version' => $version,
                'draft_version' => $locked->draft_version,
                'audience_type' => $locked->audience_type,
                'segment_definition' => $locked->segment_definition,
                'selected_client_ids' => $locked->selected_client_ids,
                'segment_summary' => $locked->segment_summary,
                'channel_priority' => $locked->channel_priority,
                'template_version_ru_id' => $locked->template_version_ru_id,
                'template_version_en_id' => $locked->template_version_en_id,
                'matched_count' => 0,
                'eligible_count' => 0,
                'suppressed_count' => 0,
                'materialized_at' => now(),
            ])->save();

            $matched = 0;
            $eligible = 0;
            $suppressed = 0;
            $batch = null;
            $batchPosition = self::BATCH_SIZE;
            $batchSequence = (int) BroadcastBatch::query()
                ->where('organization_id', $locked->organization_id)
                ->where('campaign_id', $locked->getKey())
                ->max('sequence');
            $query = $this->segments->buildForAudience(
                organizationId: (int) $locked->organization_id,
                audienceType: $locked->audience_type,
                selectedClientIds: $locked->selected_client_ids,
                filters: $locked->segment_definition,
            );

            $query->chunkById(200, function ($clients) use (&$matched, &$eligible, &$suppressed, &$batch, &$batchPosition, &$batchSequence, $locked, $snapshot): void {
                foreach ($clients as $client) {
                    $matched++;
                    $result = $this->eligibility->evaluate($client, (int) $locked->organization_id, $locked->channel_priority);
                    if ($result['eligible'] && $batchPosition >= self::BATCH_SIZE) {
                        $batchSequence++;
                        $batch = BroadcastBatch::query()->create(['organization_id' => $locked->organization_id, 'campaign_id' => $locked->getKey(), 'snapshot_id' => $snapshot->getKey(), 'sequence' => $batchSequence, 'state' => 'pending', 'available_at' => now()]);
                        $batchPosition = 0;
                    }

                    if ($result['eligible']) {
                        $eligible++;
                        $batchPosition++;
                    } else {
                        $suppressed++;
                    }

                    BroadcastRecipient::query()->create([
                        'organization_id' => $locked->organization_id,
                        'campaign_id' => $locked->getKey(),
                        'snapshot_id' => $snapshot->getKey(),
                        'batch_id' => $result['eligible'] ? $batch?->getKey() : null,
                        'client_id' => $client->getKey(),
                        'kind' => 'production',
                        'language' => $client->language ?: 'ru',
                        'channel' => $result['channel'],
                        'external_id' => $result['external_id'],
                        'render_context' => $result['eligible']
                            ? ['client' => ['full_name' => $client->full_name, 'language' => $client->language ?: 'ru']]
                            : [],
                        'state' => $result['eligible'] ? BroadcastRecipientState::Pending : BroadcastRecipientState::Suppressed,
                        'exclusion_code' => $result['reason'],
                        'idempotency_key' => hash('sha256', $locked->organization_id.'|broadcast|'.$locked->getKey().'|'.$snapshot->getKey().'|'.$client->getKey().'|production'),
                    ]);
                }
            }, 'clients.id', 'id');

            DB::table('broadcast_audience_snapshots')->where('organization_id', $locked->organization_id)->where('id', $snapshot->getKey())->update(['matched_count' => $matched, 'eligible_count' => $eligible, 'suppressed_count' => $suppressed, 'updated_at' => now()]);
            $locked->forceFill(['audience_snapshot_id' => $snapshot->getKey(), 'audience_count' => $matched, 'suppressed_count' => $suppressed])->save();

            return $snapshot->refresh();
        }, attempts: 3);
    }
}
