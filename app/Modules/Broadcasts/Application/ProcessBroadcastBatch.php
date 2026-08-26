<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Enums\BroadcastRecipientState;
use App\Modules\Broadcasts\Domain\Models\BroadcastAudienceSnapshot;
use App\Modules\Broadcasts\Domain\Models\BroadcastBatch;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Broadcasts\Domain\Models\BroadcastDeliveryAttempt;
use App\Modules\Broadcasts\Domain\Models\BroadcastRecipient;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scenarios\Domain\Contracts\NotificationTemplateRenderer;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class ProcessBroadcastBatch
{
    public function __construct(private BroadcastEligibilityPolicy $eligibility, private NotificationChannelRegistry $channels, private NotificationTemplateRenderer $renderer) {}

    public function handle(int $organizationId, int $batchId): bool
    {
        $token = (string) Str::uuid();
        $batch = $this->claimBatch($organizationId, $batchId, $token);
        if ($batch === null) {
            return false;
        }

        while ($recipient = $this->claimRecipient($organizationId, $batchId)) {
            $this->deliver($recipient);
        }

        return $this->finishBatch($organizationId, $batchId, $token);
    }

    public function deliverTest(BroadcastRecipient $recipient): void
    {
        $claimed = DB::transaction(function () use ($recipient): ?BroadcastRecipient {
            $locked = BroadcastRecipient::query()->where('organization_id', $recipient->organization_id)->whereKey($recipient->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->kind !== 'test' || $locked->state !== BroadcastRecipientState::Pending) {
                return null;
            }
            $locked->forceFill(['state' => BroadcastRecipientState::Claimed, 'lease_token' => (string) Str::uuid(), 'claimed_at' => now(), 'attempt_count' => $locked->attempt_count + 1])->save();

            return $locked->refresh();
        });
        if ($claimed !== null) {
            $this->deliver($claimed);
        }
    }

    private function claimBatch(int $organizationId, int $batchId, string $token): ?BroadcastBatch
    {
        return DB::transaction(function () use ($organizationId, $batchId, $token): ?BroadcastBatch {
            $batch = BroadcastBatch::query()->where('organization_id', $organizationId)->whereKey($batchId)->lockForUpdate()->first();
            if ($batch === null || $batch->state === 'completed') {
                return null;
            }
            $campaign = BroadcastCampaign::query()->where('organization_id', $organizationId)->whereKey($batch->campaign_id)->lockForUpdate()->first();
            if ($campaign === null || $campaign->state !== BroadcastCampaignState::Dispatching) {
                return null;
            }
            if ($batch->state === 'claimed' && $batch->claimed_at?->greaterThan(now()->subMinutes(5))) {
                return null;
            }
            if ($batch->state === 'claimed') {
                BroadcastRecipient::query()->where('organization_id', $organizationId)->where('batch_id', $batchId)->where('state', BroadcastRecipientState::Claimed->value)->update(['state' => BroadcastRecipientState::Failed->value, 'lease_token' => null, 'claimed_at' => null, 'last_error_code' => 'delivery_outcome_unknown', 'updated_at' => now()]);
            }
            $batch->forceFill(['state' => 'claimed', 'lease_token' => $token, 'claimed_at' => now()])->save();

            return $batch->refresh();
        });
    }

    private function claimRecipient(int $organizationId, int $batchId): ?BroadcastRecipient
    {
        return DB::transaction(function () use ($organizationId, $batchId): ?BroadcastRecipient {
            $recipient = BroadcastRecipient::query()->where('organization_id', $organizationId)->where('batch_id', $batchId)->where('state', BroadcastRecipientState::Pending->value)->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
            if ($recipient === null) {
                return null;
            }
            $recipient->forceFill(['state' => BroadcastRecipientState::Claimed, 'lease_token' => (string) Str::uuid(), 'claimed_at' => now(), 'attempt_count' => $recipient->attempt_count + 1])->save();

            return $recipient->refresh();
        });
    }

    private function deliver(BroadcastRecipient $recipient): void
    {
        $client = Client::query()->where('organization_id', $recipient->organization_id)->whereKey($recipient->client_id)->first();
        $eligibility = $client === null || $recipient->channel === null ? null : $this->eligibility->evaluate($client, (int) $recipient->organization_id, [$recipient->channel]);
        if ($eligibility === null || ! $eligibility['eligible'] || $eligibility['external_id'] !== $recipient->external_id) {
            $this->suppress($recipient, $eligibility['reason'] ?? 'eligibility_changed');

            return;
        }

        $result = $this->send($recipient);
        $this->finalize($recipient, $result);
    }

    private function send(BroadcastRecipient $recipient): NotificationDeliveryResult
    {
        try {
            $snapshot = BroadcastAudienceSnapshot::query()->where('organization_id', $recipient->organization_id)->whereKey($recipient->snapshot_id)->firstOrFail();
            $templateId = str_starts_with($recipient->language, 'en') ? ($snapshot->template_version_en_id ?: $snapshot->template_version_ru_id) : ($snapshot->template_version_ru_id ?: $snapshot->template_version_en_id);
            $template = NotificationTemplateVersion::query()->where('organization_id', $recipient->organization_id)->whereKey($templateId)->first();
            $channel = $this->channels->get((string) $recipient->channel);
            if ($template === null || $channel === null || ! $channel->capabilities()->supportsProactiveDelivery) {
                return NotificationDeliveryResult::unavailable('delivery_configuration_unavailable');
            }
            $rendered = $this->renderer->render($template, $recipient->render_context, $recipient->language);

            return $channel->send(new NotificationMessage((string) $recipient->external_id, $rendered->body, $rendered->subject, $rendered->locale, $recipient->idempotency_key));
        } catch (\InvalidArgumentException) {
            return NotificationDeliveryResult::permanentFailure('template_rendering_error');
        } catch (Throwable) {
            return NotificationDeliveryResult::retryable('delivery_execution_error');
        }
    }

    private function suppress(BroadcastRecipient $recipient, string $reason): void
    {
        DB::transaction(function () use ($recipient, $reason): void {
            BroadcastRecipient::query()->where('organization_id', $recipient->organization_id)->whereKey($recipient->getKey())->where('state', BroadcastRecipientState::Claimed->value)->update(['state' => BroadcastRecipientState::Suppressed->value, 'exclusion_code' => $this->safeCode($reason), 'lease_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
        });
    }

    private function finalize(BroadcastRecipient $recipient, NotificationDeliveryResult $result): void
    {
        DB::transaction(function () use ($recipient, $result): void {
            $locked = BroadcastRecipient::query()->where('organization_id', $recipient->organization_id)->whereKey($recipient->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->state !== BroadcastRecipientState::Claimed || $locked->lease_token !== $recipient->lease_token) {
                return;
            }
            BroadcastDeliveryAttempt::query()->create(['organization_id' => $locked->organization_id, 'recipient_id' => $locked->getKey(), 'attempt_number' => $locked->attempt_count, 'outcome' => $result->outcome, 'error_code' => $this->safeCode($result->errorCode), 'provider_reference' => $this->safeReference($result->providerReference), 'attempted_at' => now()]);
            $retry = $result->outcome === NotificationDeliveryOutcome::Retryable && $locked->attempt_count < 3;
            $state = $result->outcome === NotificationDeliveryOutcome::Delivered ? BroadcastRecipientState::Delivered : ($retry ? BroadcastRecipientState::Pending : BroadcastRecipientState::Failed);
            $locked->forceFill(['state' => $state, 'lease_token' => null, 'claimed_at' => null, 'next_attempt_at' => $retry ? now()->addMinutes(5) : null, 'delivered_at' => $state === BroadcastRecipientState::Delivered ? now() : null, 'last_error_code' => $this->safeCode($result->errorCode), 'provider_reference' => $this->safeReference($result->providerReference)])->save();
        });
    }

    private function finishBatch(int $organizationId, int $batchId, string $token): bool
    {
        return DB::transaction(function () use ($organizationId, $batchId, $token): bool {
            $batch = BroadcastBatch::query()->where('organization_id', $organizationId)->whereKey($batchId)->lockForUpdate()->firstOrFail();
            if ($batch->lease_token !== $token) {
                return false;
            }
            $hasPending = BroadcastRecipient::query()->where('organization_id', $organizationId)->where('batch_id', $batchId)->whereIn('state', [BroadcastRecipientState::Pending->value, BroadcastRecipientState::Claimed->value])->exists();
            $batch->forceFill(['state' => $hasPending ? 'pending' : 'completed', 'lease_token' => null, 'claimed_at' => null, 'completed_at' => $hasPending ? null : now()])->save();
            $this->reconcileCampaign($organizationId, (int) $batch->campaign_id);

            return $hasPending;
        });
    }

    private function reconcileCampaign(int $organizationId, int $campaignId): void
    {
        $campaign = BroadcastCampaign::query()->where('organization_id', $organizationId)->whereKey($campaignId)->lockForUpdate()->firstOrFail();
        $recipients = BroadcastRecipient::query()->where('organization_id', $organizationId)->where('campaign_id', $campaignId)->where('kind', 'production');
        $delivered = (clone $recipients)->where('state', BroadcastRecipientState::Delivered->value)->count();
        $failed = (clone $recipients)->where('state', BroadcastRecipientState::Failed->value)->count();
        $suppressed = (clone $recipients)->where('state', BroadcastRecipientState::Suppressed->value)->count();
        $sent = (clone $recipients)->where('attempt_count', '>', 0)->count();
        $unfinished = BroadcastBatch::query()->where('organization_id', $organizationId)->where('campaign_id', $campaignId)->where('state', '!=', 'completed')->exists();
        $campaign->forceFill(['sent_count' => $sent, 'delivered_count' => $delivered, 'failed_count' => $failed, 'suppressed_count' => $suppressed, 'state' => $unfinished ? BroadcastCampaignState::Dispatching : BroadcastCampaignState::Completed, 'completed_at' => $unfinished ? null : now()])->save();
    }

    private function safeCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strtolower(trim($value));

        return preg_match('/^[a-z0-9_.:-]{1,64}$/', $value) === 1 ? $value : 'provider_error';
    }

    private function safeReference(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = substr(trim($value), 0, 191);
        $value = preg_replace('/[^a-zA-Z0-9._:-]/', '_', $value) ?? '';

        return $value === '' ? null : $value;
    }
}
