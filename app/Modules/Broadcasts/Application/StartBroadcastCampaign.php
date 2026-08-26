<?php

namespace App\Modules\Broadcasts\Application;

use App\Models\User;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class StartBroadcastCampaign
{
    public function __construct(private BroadcastAuthorization $authorization, private MaterializeBroadcastAudience $materializer, private ScheduleBroadcastWork $scheduler, private RecordAuditEvent $audit) {}

    public function handle(User $actor, BroadcastCampaign $campaign): BroadcastCampaign
    {
        $organization = $this->authorization->manage($actor);
        if ((int) $campaign->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The campaign is outside the current organization.');
        }
        if ($campaign->state !== BroadcastCampaignState::Draft) {
            throw ValidationException::withMessages(['campaign' => 'Рассылка уже запланирована или завершена.']);
        }

        $snapshot = $this->materializer->handle($campaign);
        DB::transaction(function () use ($actor, $campaign, $organization, $snapshot): void {
            $locked = BroadcastCampaign::query()->where('organization_id', $organization->getKey())->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->state !== BroadcastCampaignState::Draft
                || (int) $locked->audience_snapshot_id !== $snapshot->getKey()
                || (int) $snapshot->draft_version !== (int) $locked->draft_version) {
                throw ValidationException::withMessages(['campaign' => 'Состояние рассылки изменилось. Обновите страницу.']);
            }
            if ($locked->send_mode === 'scheduled' && $locked->scheduled_at === null) {
                throw ValidationException::withMessages(['scheduled_at' => 'Для запланированной рассылки не указано время.']);
            }
            if ($locked->send_mode === 'scheduled' && $locked->scheduled_at->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['scheduled_at' => 'Время запланированной отправки должно быть в будущем.']);
            }
            $when = $locked->send_mode === 'scheduled'
                ? CarbonImmutable::instance($locked->scheduled_at)->utc()
                : CarbonImmutable::now();
            $locked->forceFill(['state' => BroadcastCampaignState::Scheduled, 'scheduled_at' => $when])->save();
            $this->audit->handle($organization, $actor, $locked->send_mode === 'scheduled' ? 'broadcast.campaign.scheduled' : 'broadcast.campaign.started', BroadcastCampaign::class, (string) $locked->getKey(), ['audience_count' => $snapshot->matched_count, 'eligible_count' => $snapshot->eligible_count, 'scheduled_at' => $when->toIso8601String()]);
        });
        $this->scheduler->handle();

        return $campaign->refresh();
    }
}
