<?php

namespace App\Modules\Broadcasts\Application;

use App\Models\User;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final readonly class PreviewBroadcastCampaign
{
    public function __construct(private BroadcastAuthorization $authorization, private BroadcastSegmentQuery $segments, private BroadcastEligibilityPolicy $eligibility, private RecordAuditEvent $audit) {}

    /** @return array{matched: int, eligible: int, suppressed: int, reasons: array<string, int>, summary: string} */
    public function handle(User $actor, BroadcastCampaign $campaign): array
    {
        $organization = $this->authorization->manage($actor);
        if ((int) $campaign->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The campaign is outside the current organization.');
        }
        if ($campaign->state !== BroadcastCampaignState::Draft) {
            throw ValidationException::withMessages(['campaign' => 'Предпросмотр доступен только для черновика.']);
        }

        $matched = 0;
        $eligible = 0;
        $reasons = [];
        $query = $this->segments->build($organization->getKey(), $campaign->segment_definition);
        $query->chunkById(200, function ($clients) use (&$matched, &$eligible, &$reasons, $campaign, $organization): void {
            foreach ($clients as $client) {
                $matched++;
                $result = $this->eligibility->evaluate($client, $organization->getKey(), $campaign->channel_priority);
                if ($result['eligible']) {
                    $eligible++;
                } else {
                    $reason = $result['reason'] ?? 'ineligible';
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                }
            }
        }, 'clients.id', 'id');
        $this->audit->handle($organization, $actor, 'broadcast.campaign.previewed', BroadcastCampaign::class, (string) $campaign->getKey(), ['matched_count' => $matched, 'eligible_count' => $eligible, 'suppressed_count' => $matched - $eligible]);

        return ['matched' => $matched, 'eligible' => $eligible, 'suppressed' => $matched - $eligible, 'reasons' => $reasons, 'summary' => $campaign->segment_summary];
    }
}
