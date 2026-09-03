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
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\Exceptions\NotificationDeliveryException;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Contracts\NotificationTemplateRenderer;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Support\RichText\RichTextDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ProcessBroadcastBatch
{
    private const LEASE_MINUTES = 5;

    private const MAX_RECIPIENT_ATTEMPTS = 3;

    public function __construct(
        private BroadcastEligibilityPolicy $eligibility,
        private NotificationChannelRegistry $channels,
        private NotificationTemplateRenderer $renderer,
        private BroadcastAuthorization $authorization,
        private RecordAuditEvent $audit,
        private BroadcastCampaignMedia $media,
    ) {}

    public function handle(int $organizationId, int $batchId, ?string $leaseToken = null): bool
    {
        $token = $leaseToken === null || $leaseToken === '' ? (string) Str::uuid() : $leaseToken;
        $batch = $this->claimBatch($organizationId, $batchId, $token);
        if ($batch === null) {
            return false;
        }

        while (($recipient = $this->claimRecipient($organizationId, $batchId, $token)) !== null) {
            $this->deliver($recipient);
        }

        return $this->finishBatch($organizationId, $batchId, $token);
    }

    public function deliverTest(BroadcastRecipient $recipient): BroadcastRecipient
    {
        $claimed = DB::transaction(function () use ($recipient): ?BroadcastRecipient {
            $campaign = BroadcastCampaign::query()
                ->where('organization_id', $recipient->organization_id)
                ->whereKey($recipient->campaign_id)
                ->lockForUpdate()
                ->first();
            $locked = BroadcastRecipient::query()
                ->where('organization_id', $recipient->organization_id)
                ->whereKey($recipient->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null || $locked->kind !== 'test' || $locked->state !== BroadcastRecipientState::Pending) {
                return null;
            }
            if ($campaign === null || $campaign->state !== BroadcastCampaignState::Draft) {
                $locked->forceFill([
                    'state' => BroadcastRecipientState::Failed,
                    'last_error_code' => 'campaign_state_changed',
                    'next_attempt_at' => null,
                    'render_context' => [],
                ])->save();

                return null;
            }

            $locked->forceFill([
                'state' => BroadcastRecipientState::Claimed,
                'lease_token' => (string) Str::uuid(),
                'claimed_at' => now(),
                'attempt_count' => $locked->attempt_count + 1,
            ])->save();

            return $locked->refresh();
        });

        if ($claimed !== null) {
            $this->deliver($claimed);
        }

        return $recipient->refresh();
    }

    public function markJobFailed(int $organizationId, int $batchId, ?string $errorCode = null, ?string $leaseToken = null): void
    {
        DB::transaction(function () use ($organizationId, $batchId, $errorCode, $leaseToken): void {
            $batchReference = BroadcastBatch::query()
                ->where('organization_id', $organizationId)
                ->whereKey($batchId)
                ->first();
            if ($batchReference === null) {
                return;
            }

            $campaign = BroadcastCampaign::query()
                ->where('organization_id', $organizationId)
                ->whereKey($batchReference->campaign_id)
                ->lockForUpdate()
                ->first();
            $batch = BroadcastBatch::query()
                ->where('organization_id', $organizationId)
                ->whereKey($batchId)
                ->lockForUpdate()
                ->first();
            if ($batch === null || in_array($batch->state, ['completed', 'failed'], true)) {
                return;
            }
            if ($batch->state === 'claimed' && ($leaseToken === null || $batch->lease_token !== $leaseToken)) {
                return;
            }

            $this->markClaimedRecipientsUnknown($organizationId, $batch);
            if ($campaign === null || $campaign->state->isTerminal()) {
                BroadcastRecipient::query()
                    ->where('organization_id', $organizationId)
                    ->where('batch_id', $batch->getKey())
                    ->where('state', BroadcastRecipientState::Pending->value)
                    ->update([
                        'state' => BroadcastRecipientState::Failed->value,
                        'lease_token' => null,
                        'claimed_at' => null,
                        'next_attempt_at' => null,
                        'last_error_code' => 'queue_job_failed_terminal',
                        'render_context' => [],
                        'updated_at' => now(),
                    ]);
                $batch->forceFill([
                    'state' => 'failed',
                    'lease_token' => null,
                    'claimed_at' => null,
                    'available_at' => null,
                    'completed_at' => now(),
                    'last_dispatch_error_code' => $this->safeCode($errorCode) ?? 'queue_job_failed_terminal',
                ])->save();

                return;
            }
            $batch->forceFill([
                'state' => 'pending',
                'lease_token' => null,
                'claimed_at' => null,
                'available_at' => now()->addMinutes(5),
                'last_dispatch_error_code' => $this->safeCode($errorCode) ?? 'queue_job_failed',
            ])->save();
        });
    }

    private function claimBatch(int $organizationId, int $batchId, string $token): ?BroadcastBatch
    {
        return DB::transaction(function () use ($organizationId, $batchId, $token): ?BroadcastBatch {
            $batchReference = BroadcastBatch::query()
                ->where('organization_id', $organizationId)
                ->whereKey($batchId)
                ->first();
            if ($batchReference === null) {
                return null;
            }

            $campaign = BroadcastCampaign::query()
                ->where('organization_id', $organizationId)
                ->whereKey($batchReference->campaign_id)
                ->lockForUpdate()
                ->first();
            $batch = BroadcastBatch::query()
                ->where('organization_id', $organizationId)
                ->whereKey($batchId)
                ->lockForUpdate()
                ->first();
            $snapshot = $batch === null
                ? null
                : BroadcastAudienceSnapshot::query()
                    ->where('organization_id', $organizationId)
                    ->whereKey($batch->snapshot_id)
                    ->first();
            if ($batch === null || in_array($batch->state, ['completed', 'failed'], true)) {
                return null;
            }
            if ($campaign === null
                || $campaign->state !== BroadcastCampaignState::Dispatching
                || (int) $batch->snapshot_id !== (int) $campaign->audience_snapshot_id
                || $snapshot === null
                || (int) $snapshot->campaign_id !== (int) $campaign->getKey()
                || (int) $snapshot->draft_version !== (int) $campaign->draft_version) {
                $this->failBatchForReason($organizationId, $batch, 'snapshot_superseded');

                return null;
            }

            if (! $this->authorization->creatorCanExecute($campaign)) {
                $this->cancelForRevokedAuthority($campaign);

                return null;
            }

            if ($batch->state === 'claimed' && $batch->claimed_at?->greaterThan(now()->subMinutes(self::LEASE_MINUTES))) {
                return null;
            }
            if ($batch->state === 'claimed') {
                $this->markClaimedRecipientsUnknown($organizationId, $batch);
            }

            $batch->forceFill([
                'state' => 'claimed',
                'lease_token' => $token,
                'claimed_at' => now(),
            ])->save();

            return $batch->refresh();
        });
    }

    private function claimRecipient(int $organizationId, int $batchId, string $batchToken): ?BroadcastRecipient
    {
        return DB::transaction(function () use ($organizationId, $batchId, $batchToken): ?BroadcastRecipient {
            $batch = BroadcastBatch::query()
                ->where('organization_id', $organizationId)
                ->whereKey($batchId)
                ->lockForUpdate()
                ->first();
            if ($batch === null || $batch->state !== 'claimed' || $batch->lease_token !== $batchToken) {
                return null;
            }

            $recipient = BroadcastRecipient::query()
                ->where('organization_id', $organizationId)
                ->where('batch_id', $batchId)
                ->where('state', BroadcastRecipientState::Pending->value)
                ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();
            if ($recipient === null) {
                return null;
            }

            $recipient->forceFill([
                'state' => BroadcastRecipientState::Claimed,
                'lease_token' => (string) Str::uuid(),
                'claimed_at' => now(),
                'attempt_count' => $recipient->attempt_count + 1,
            ])->save();

            return $recipient->refresh();
        });
    }

    private function deliver(BroadcastRecipient $recipient): void
    {
        $campaign = BroadcastCampaign::query()
            ->where('organization_id', $recipient->organization_id)
            ->whereKey($recipient->campaign_id)
            ->first();
        if ($campaign === null || ! $this->authorization->creatorCanExecute($campaign)) {
            $this->markAuthorizationRevoked($recipient);

            return;
        }
        $requiredState = $recipient->kind === 'test'
            ? BroadcastCampaignState::Draft
            : BroadcastCampaignState::Dispatching;
        if ($campaign->state !== $requiredState) {
            $this->failClaimedRecipient($recipient, 'campaign_state_changed');

            return;
        }

        $snapshot = BroadcastAudienceSnapshot::query()
            ->where('organization_id', $recipient->organization_id)
            ->whereKey($recipient->snapshot_id)
            ->first();
        if ($snapshot === null
            || (int) $snapshot->campaign_id !== (int) $campaign->getKey()
            || (int) $snapshot->draft_version !== (int) $campaign->draft_version) {
            $this->failClaimedRecipient($recipient, 'snapshot_superseded');

            return;
        }

        $client = Client::query()
            ->where('organization_id', $recipient->organization_id)
            ->whereKey($recipient->client_id)
            ->first();
        $eligibility = $client === null || $recipient->channel === null
            ? null
            : $this->eligibility->evaluate($client, (int) $recipient->organization_id, [$recipient->channel]);
        if ($eligibility === null || ! $eligibility['eligible'] || $eligibility['external_id'] !== $recipient->external_id) {
            $this->suppress($recipient, $eligibility['reason'] ?? 'eligibility_changed');

            return;
        }

        $attemptNumber = $this->beginAttempt($recipient);
        if ($attemptNumber === null) {
            return;
        }

        $result = $this->send($recipient);
        $this->finalize($recipient, $attemptNumber, $result);
    }

    private function beginAttempt(BroadcastRecipient $recipient): ?int
    {
        return DB::transaction(function () use ($recipient): ?int {
            $campaign = BroadcastCampaign::query()
                ->where('organization_id', $recipient->organization_id)
                ->whereKey($recipient->campaign_id)
                ->lockForUpdate()
                ->first();
            $locked = BroadcastRecipient::query()
                ->where('organization_id', $recipient->organization_id)
                ->whereKey($recipient->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null || $locked->state !== BroadcastRecipientState::Claimed || $locked->lease_token !== $recipient->lease_token) {
                return null;
            }

            $requiredState = $locked->kind === 'test'
                ? BroadcastCampaignState::Draft
                : BroadcastCampaignState::Dispatching;
            if ($campaign === null || $campaign->state !== $requiredState) {
                $locked->forceFill([
                    'state' => BroadcastRecipientState::Failed,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'last_error_code' => 'campaign_state_changed',
                    'render_context' => [],
                ])->save();

                return null;
            }
            if (! $this->authorization->creatorCanExecute($campaign)) {
                $locked->forceFill([
                    'state' => BroadcastRecipientState::Failed,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'last_error_code' => 'authorization_revoked',
                    'render_context' => [],
                ])->save();
                $this->cancelForRevokedAuthority($campaign);

                return null;
            }

            $attempt = BroadcastDeliveryAttempt::query()
                ->where('organization_id', $locked->organization_id)
                ->where('recipient_id', $locked->getKey())
                ->where('attempt_number', $locked->attempt_count)
                ->lockForUpdate()
                ->first();
            if ($attempt !== null) {
                if ($attempt->outcome === NotificationDeliveryOutcome::InFlight) {
                    $attempt->forceFill([
                        'outcome' => NotificationDeliveryOutcome::Unknown,
                        'error_code' => 'delivery_outcome_unknown',
                        'completed_at' => now(),
                    ])->save();
                    $locked->forceFill([
                        'state' => BroadcastRecipientState::Failed,
                        'lease_token' => null,
                        'claimed_at' => null,
                        'next_attempt_at' => null,
                        'last_error_code' => 'delivery_outcome_unknown',
                        'render_context' => [],
                    ])->save();
                } elseif ($attempt->outcome === NotificationDeliveryOutcome::Delivered) {
                    $locked->forceFill([
                        'state' => BroadcastRecipientState::Delivered,
                        'lease_token' => null,
                        'claimed_at' => null,
                        'next_attempt_at' => null,
                        'delivered_at' => $attempt->completed_at ?? now(),
                        'last_error_code' => null,
                        'provider_reference' => $attempt->provider_reference,
                        'render_context' => [],
                    ])->save();
                } elseif ($attempt->outcome === NotificationDeliveryOutcome::Suppressed) {
                    $locked->forceFill([
                        'state' => BroadcastRecipientState::Suppressed,
                        'lease_token' => null,
                        'claimed_at' => null,
                        'next_attempt_at' => null,
                        'exclusion_code' => $attempt->error_code,
                        'render_context' => [],
                    ])->save();
                } else {
                    $locked->forceFill([
                        'state' => BroadcastRecipientState::Failed,
                        'lease_token' => null,
                        'claimed_at' => null,
                        'next_attempt_at' => null,
                        'last_error_code' => $attempt->error_code ?? 'delivery_outcome_unknown',
                        'render_context' => [],
                    ])->save();
                }

                return null;
            }

            $startedAt = now();
            $attempt = new BroadcastDeliveryAttempt;
            $attempt->forceFill([
                'organization_id' => $locked->organization_id,
                'recipient_id' => $locked->getKey(),
                'campaign_id' => $locked->campaign_id,
                'snapshot_id' => $locked->snapshot_id,
                'batch_id' => $locked->batch_id,
                'channel' => $locked->channel,
                'idempotency_key' => $locked->idempotency_key,
                'attempt_number' => $locked->attempt_count,
                'outcome' => NotificationDeliveryOutcome::InFlight,
                'started_at' => $startedAt,
                'attempted_at' => $startedAt,
            ])->save();

            return (int) $attempt->attempt_number;
        });
    }

    private function send(BroadcastRecipient $recipient): NotificationDeliveryResult
    {
        try {
            $campaign = BroadcastCampaign::query()
                ->where('organization_id', $recipient->organization_id)
                ->whereKey($recipient->campaign_id)
                ->first();
            $requiredState = $recipient->kind === 'test'
                ? BroadcastCampaignState::Draft
                : BroadcastCampaignState::Dispatching;
            if ($campaign === null
                || $campaign->state !== $requiredState
                || ($recipient->kind !== 'test' && (int) $campaign->audience_snapshot_id !== (int) $recipient->snapshot_id)) {
                return NotificationDeliveryResult::permanentFailure('campaign_state_changed');
            }
            if (! $this->authorization->creatorCanExecute($campaign)) {
                return NotificationDeliveryResult::permanentFailure('authorization_revoked');
            }

            $client = Client::query()
                ->where('organization_id', $recipient->organization_id)
                ->whereKey($recipient->client_id)
                ->first();
            $eligibility = $client === null || $recipient->channel === null
                ? null
                : $this->eligibility->evaluate($client, (int) $recipient->organization_id, [$recipient->channel]);
            if ($eligibility === null || ! $eligibility['eligible'] || $eligibility['external_id'] !== $recipient->external_id) {
                return NotificationDeliveryResult::suppressed($eligibility['reason'] ?? 'eligibility_changed');
            }

            $snapshot = BroadcastAudienceSnapshot::query()
                ->where('organization_id', $recipient->organization_id)
                ->whereKey($recipient->snapshot_id)
                ->firstOrFail();
            if ((int) $snapshot->campaign_id !== (int) $campaign->getKey()
                || (int) $snapshot->draft_version !== (int) $campaign->draft_version) {
                return NotificationDeliveryResult::permanentFailure('snapshot_superseded');
            }
            $templateId = str_starts_with($recipient->language, 'en')
                ? ($snapshot->template_version_en_id ?: $snapshot->template_version_ru_id)
                : ($snapshot->template_version_ru_id ?: $snapshot->template_version_en_id);
            $deliveryMode = NotificationMessageMode::tryFrom((string) $snapshot->delivery_mode);
            if (! $deliveryMode instanceof NotificationMessageMode) {
                return NotificationDeliveryResult::permanentFailure('delivery_configuration_invalid');
            }
            $media = is_array($snapshot->media) ? $snapshot->media : null;
            $mediaImage = is_string($media['image'] ?? null) ? trim($media['image']) : null;
            if ($deliveryMode->includesImage() && ($mediaImage === null || $mediaImage === '')) {
                return NotificationDeliveryResult::unavailable('media_unavailable');
            }
            $mediaUrl = $mediaImage;
            $managedMedia = $mediaImage !== null
                && $this->media->isManagedPath((int) $recipient->organization_id, $mediaImage);
            if ($managedMedia) {
                $mediaUrl = null;
            }
            $imageOnly = ! $deliveryMode->includesText();
            $isComposedMessage = $campaign->message_mode === 'compose';
            $channel = $this->channels->get((string) $recipient->channel);
            if ($channel === null || ! $channel->capabilities()->supportsProactiveDelivery) {
                return NotificationDeliveryResult::unavailable('telegram_channel_unavailable');
            }

            $template = null;
            if (! $imageOnly && ! $isComposedMessage) {
                $template = NotificationTemplateVersion::query()
                    ->where('organization_id', $recipient->organization_id)
                    ->whereKey($templateId)
                    ->with('template')
                    ->first();
                if ($template === null) {
                    return NotificationDeliveryResult::unavailable('template_unavailable');
                }
                if (! $this->isActiveMarketingTemplate($template, (int) $recipient->organization_id)) {
                    return NotificationDeliveryResult::unavailable('template_inactive_or_wrong_purpose');
                }
            }
            $renderedBody = '';
            $renderedSubject = null;
            $renderedLocale = $recipient->language;
            if (! $imageOnly) {
                $allowedVariables = ScenarioTemplateVariableCatalog::allowedForPurpose(ScenarioRulePurpose::Marketing);
                if ($isComposedMessage) {
                    $body = is_string($campaign->message_body) ? trim($campaign->message_body) : '';
                    if ($body === '') {
                        return NotificationDeliveryResult::permanentFailure('content_unavailable');
                    }
                    try {
                        $usedVariables = ScenarioTemplateVariableCatalog::used($body);
                    } catch (\InvalidArgumentException) {
                        return NotificationDeliveryResult::permanentFailure('template_rendering_error');
                    }
                    if (array_diff($usedVariables, $allowedVariables) !== []) {
                        return NotificationDeliveryResult::permanentFailure('template_rendering_error');
                    }
                    $template = new NotificationTemplateVersion;
                    $template->forceFill([
                        'body' => $body,
                        'variables' => $usedVariables,
                    ]);
                }
                if ($template === null) {
                    return NotificationDeliveryResult::unavailable('template_unavailable');
                }
                if (array_diff($template->variables, $allowedVariables) !== []) {
                    return NotificationDeliveryResult::permanentFailure('template_rendering_error');
                }
                $usedVariables = ScenarioTemplateVariableCatalog::used($template->body, (string) $template->subject);
                if (array_diff($usedVariables, $template->variables) !== []) {
                    return NotificationDeliveryResult::permanentFailure('template_rendering_error');
                }

                $rendered = $this->renderer->render($template, $recipient->render_context, $recipient->language);
                $renderedBody = $rendered->body;
                $renderedSubject = $rendered->subject;
                $renderedLocale = $rendered->locale;
            }
            try {
                $limit = $deliveryMode->usesCaption()
                    ? RichTextDocument::TELEGRAM_CAPTION_LIMIT
                    : RichTextDocument::TELEGRAM_TEXT_LIMIT;
                if ($deliveryMode->includesText() && RichTextDocument::telegramLength($renderedBody) > $limit) {
                    return NotificationDeliveryResult::permanentFailure('telegram_message_too_long');
                }
            } catch (\InvalidArgumentException) {
                return NotificationDeliveryResult::permanentFailure('template_rendering_error');
            }
            $currentCampaign = BroadcastCampaign::query()
                ->where('organization_id', $recipient->organization_id)
                ->whereKey($recipient->campaign_id)
                ->first();
            if ($currentCampaign === null
                || $currentCampaign->state !== $requiredState
                || ($recipient->kind !== 'test' && (int) $currentCampaign->audience_snapshot_id !== (int) $recipient->snapshot_id)
                || (int) $snapshot->draft_version !== (int) $currentCampaign->draft_version) {
                return NotificationDeliveryResult::permanentFailure('campaign_state_changed');
            }
            if (! $this->authorization->creatorCanExecute($currentCampaign)) {
                return NotificationDeliveryResult::permanentFailure('authorization_revoked');
            }

            $currentClient = Client::query()
                ->where('organization_id', $recipient->organization_id)
                ->whereKey($recipient->client_id)
                ->first();
            $currentEligibility = $currentClient === null
                ? null
                : $this->eligibility->evaluate(
                    $currentClient,
                    (int) $recipient->organization_id,
                    [$recipient->channel],
                );
            if ($currentEligibility === null
                || ! $currentEligibility['eligible']
                || $currentEligibility['external_id'] !== $recipient->external_id) {
                return NotificationDeliveryResult::suppressed($currentEligibility['reason'] ?? 'eligibility_changed');
            }

            if (! $imageOnly && ! $isComposedMessage) {
                $currentTemplate = NotificationTemplateVersion::query()
                    ->where('organization_id', $recipient->organization_id)
                    ->whereKey($template->getKey())
                    ->with('template')
                    ->first();
                if ($currentTemplate === null) {
                    return NotificationDeliveryResult::unavailable('template_unavailable');
                }
                if (! $this->isActiveMarketingTemplate($currentTemplate, (int) $recipient->organization_id)) {
                    return NotificationDeliveryResult::unavailable('template_inactive_or_wrong_purpose');
                }
            }

            $mediaStream = null;
            if ($deliveryMode->includesImage() && $managedMedia) {
                $mediaStream = $this->media->readStream((int) $recipient->organization_id, (string) $mediaImage);
                if (! is_resource($mediaStream)) {
                    return NotificationDeliveryResult::unavailable('media_unavailable');
                }
            }

            $message = new NotificationMessage(
                (string) $recipient->external_id,
                $renderedBody,
                $renderedSubject,
                $renderedLocale,
                $recipient->idempotency_key,
                true,
                mediaUrl: $mediaUrl,
                mediaStream: $mediaStream,
                mode: $deliveryMode,
                showCaptionAboveMedia: $snapshot->caption_position === 'above',
            );
        } catch (\InvalidArgumentException) {
            return NotificationDeliveryResult::permanentFailure('template_rendering_error');
        } catch (\Throwable) {
            return NotificationDeliveryResult::unavailable('delivery_configuration_unavailable');
        }

        try {
            return $channel->send($message);
        } catch (NotificationDeliveryException $exception) {
            return $exception->externalSendStarted
                ? NotificationDeliveryResult::unknown('delivery_outcome_unknown')
                : NotificationDeliveryResult::retryable('delivery_pre_send_failure');
        } catch (\Throwable) {
            return NotificationDeliveryResult::unknown('delivery_outcome_unknown');
        }
    }

    private function suppress(BroadcastRecipient $recipient, string $reason): void
    {
        DB::transaction(function () use ($recipient, $reason): void {
            BroadcastRecipient::query()
                ->where('organization_id', $recipient->organization_id)
                ->whereKey($recipient->getKey())
                ->where('state', BroadcastRecipientState::Claimed->value)
                ->where('lease_token', $recipient->lease_token)
                ->update([
                    'state' => BroadcastRecipientState::Suppressed->value,
                    'exclusion_code' => $this->safeCode($reason),
                    'render_context' => [],
                    'lease_token' => null,
                    'claimed_at' => null,
                    'updated_at' => now(),
                ]);
        });
    }

    private function failClaimedRecipient(BroadcastRecipient $recipient, string $reason): void
    {
        DB::transaction(function () use ($recipient, $reason): void {
            BroadcastRecipient::query()
                ->where('organization_id', $recipient->organization_id)
                ->whereKey($recipient->getKey())
                ->where('state', BroadcastRecipientState::Claimed->value)
                ->where('lease_token', $recipient->lease_token)
                ->update([
                    'state' => BroadcastRecipientState::Failed->value,
                    'last_error_code' => $this->safeCode($reason),
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'render_context' => [],
                    'updated_at' => now(),
                ]);
        });
    }

    private function finalize(BroadcastRecipient $recipient, int $attemptNumber, NotificationDeliveryResult $result): void
    {
        DB::transaction(function () use ($recipient, $attemptNumber, $result): void {
            $campaign = BroadcastCampaign::query()
                ->where('organization_id', $recipient->organization_id)
                ->whereKey($recipient->campaign_id)
                ->lockForUpdate()
                ->first();
            $locked = BroadcastRecipient::query()
                ->where('organization_id', $recipient->organization_id)
                ->whereKey($recipient->getKey())
                ->lockForUpdate()
                ->first();
            if ($campaign === null || $locked === null || $locked->state !== BroadcastRecipientState::Claimed || $locked->lease_token !== $recipient->lease_token) {
                return;
            }

            $attempt = BroadcastDeliveryAttempt::query()
                ->where('organization_id', $locked->organization_id)
                ->where('recipient_id', $locked->getKey())
                ->where('attempt_number', $attemptNumber)
                ->lockForUpdate()
                ->first();
            if ($attempt === null || $attempt->outcome !== NotificationDeliveryOutcome::InFlight) {
                return;
            }

            $outcome = $result->outcome === NotificationDeliveryOutcome::InFlight
                ? NotificationDeliveryOutcome::Unknown
                : $result->outcome;
            $errorCode = $outcome === NotificationDeliveryOutcome::Unknown
                ? 'delivery_outcome_unknown'
                : $this->safeCode($result->errorCode);
            $providerReference = $this->safeReference($result->providerReference);
            $retry = $locked->kind !== 'test'
                && $outcome === NotificationDeliveryOutcome::Retryable
                && $locked->attempt_count < self::MAX_RECIPIENT_ATTEMPTS;
            $state = match (true) {
                $outcome === NotificationDeliveryOutcome::Delivered => BroadcastRecipientState::Delivered,
                $outcome === NotificationDeliveryOutcome::Suppressed => BroadcastRecipientState::Suppressed,
                $retry => BroadcastRecipientState::Pending,
                default => BroadcastRecipientState::Failed,
            };

            $attempt->forceFill([
                'outcome' => $outcome,
                'error_code' => $errorCode,
                'provider_reference' => $providerReference,
                'completed_at' => now(),
            ])->save();
            $locked->forceFill([
                'state' => $state,
                'lease_token' => null,
                'claimed_at' => null,
                'next_attempt_at' => $retry ? now()->addMinutes(5) : null,
                'delivered_at' => $state === BroadcastRecipientState::Delivered ? now() : null,
                'last_error_code' => $errorCode,
                'provider_reference' => $providerReference,
                'render_context' => $state === BroadcastRecipientState::Pending ? $locked->render_context : [],
            ])->save();
            if ($errorCode === 'authorization_revoked' && ! $campaign->state->isTerminal()) {
                $campaign->forceFill([
                    'state' => BroadcastCampaignState::Cancelled,
                    'cancelled_at' => now(),
                    'next_dispatch_at' => null,
                    'last_dispatch_error_code' => 'authorization_revoked',
                ])->save();
                $this->audit->handle(
                    Organization::query()->findOrFail($campaign->organization_id),
                    null,
                    'broadcast.campaign.execution_blocked',
                    BroadcastCampaign::class,
                    (string) $campaign->getKey(),
                    ['reason' => 'creator_authority_revoked'],
                );
            }
        });
    }

    private function isActiveMarketingTemplate(?NotificationTemplateVersion $template, int $organizationId): bool
    {
        return $template !== null
            && $template->status === NotificationTemplateStatus::Published
            && $template->template !== null
            && (int) $template->template->organization_id === $organizationId
            && $template->template->is_active
            && $template->template->purpose === ScenarioRulePurpose::Marketing->value;
    }

    private function finishBatch(int $organizationId, int $batchId, string $token): bool
    {
        return DB::transaction(function () use ($organizationId, $batchId, $token): bool {
            $batchReference = BroadcastBatch::query()
                ->where('organization_id', $organizationId)
                ->whereKey($batchId)
                ->first();
            if ($batchReference === null) {
                return false;
            }
            $campaign = BroadcastCampaign::query()
                ->where('organization_id', $organizationId)
                ->whereKey($batchReference->campaign_id)
                ->lockForUpdate()
                ->first();
            if ($campaign === null) {
                return false;
            }
            $batch = BroadcastBatch::query()
                ->where('organization_id', $organizationId)
                ->whereKey($batchId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($batch->lease_token !== $token) {
                return false;
            }

            $hasPending = BroadcastRecipient::query()
                ->where('organization_id', $organizationId)
                ->where('batch_id', $batchId)
                ->whereIn('state', [BroadcastRecipientState::Pending->value, BroadcastRecipientState::Claimed->value])
                ->exists();
            $availableAt = null;
            if ($hasPending) {
                $availableAt = BroadcastRecipient::query()
                    ->where('organization_id', $organizationId)
                    ->where('batch_id', $batchId)
                    ->where('state', BroadcastRecipientState::Pending->value)
                    ->whereNotNull('next_attempt_at')
                    ->min('next_attempt_at');
            }
            $batch->forceFill([
                'state' => $hasPending ? 'pending' : 'completed',
                'lease_token' => null,
                'claimed_at' => null,
                'available_at' => $hasPending ? ($availableAt === null ? now() : Carbon::parse($availableAt)) : null,
                'completed_at' => $hasPending ? null : now(),
            ])->save();
            $this->reconcileCampaign($organizationId, (int) $batch->campaign_id);

            return $hasPending;
        });
    }

    private function markClaimedRecipientsUnknown(int $organizationId, BroadcastBatch $batch): void
    {
        $recipients = BroadcastRecipient::query()
            ->where('organization_id', $organizationId)
            ->where('batch_id', $batch->getKey())
            ->where('state', BroadcastRecipientState::Claimed->value)
            ->lockForUpdate()
            ->get();
        foreach ($recipients as $recipient) {
            $attempt = BroadcastDeliveryAttempt::query()
                ->where('organization_id', $organizationId)
                ->where('recipient_id', $recipient->getKey())
                ->where('attempt_number', $recipient->attempt_count)
                ->lockForUpdate()
                ->first();
            if ($attempt === null) {
                $retry = $recipient->attempt_count < self::MAX_RECIPIENT_ATTEMPTS;
                $recipient->forceFill([
                    'state' => $retry ? BroadcastRecipientState::Pending : BroadcastRecipientState::Failed,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => $retry ? now()->addMinutes(5) : null,
                    'last_error_code' => 'delivery_pre_send_failure',
                    'render_context' => $retry ? $recipient->render_context : [],
                ])->save();

                continue;
            }
            if ($attempt->outcome === NotificationDeliveryOutcome::InFlight) {
                $attempt->forceFill([
                    'outcome' => NotificationDeliveryOutcome::Unknown,
                    'error_code' => 'delivery_outcome_unknown',
                    'completed_at' => now(),
                ])->save();
            }
            $recipient->forceFill(match ($attempt->outcome) {
                NotificationDeliveryOutcome::Delivered => [
                    'state' => BroadcastRecipientState::Delivered,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'delivered_at' => $attempt->completed_at ?? now(),
                    'last_error_code' => null,
                    'provider_reference' => $attempt->provider_reference,
                    'render_context' => [],
                ],
                NotificationDeliveryOutcome::Suppressed => [
                    'state' => BroadcastRecipientState::Suppressed,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'exclusion_code' => $attempt->error_code,
                    'render_context' => [],
                ],
                default => [
                    'state' => BroadcastRecipientState::Failed,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'last_error_code' => $attempt->error_code ?? 'delivery_outcome_unknown',
                    'render_context' => [],
                ],
            })->save();
        }
    }

    private function failBatchForReason(int $organizationId, BroadcastBatch $batch, string $reason): void
    {
        $this->markClaimedRecipientsUnknown($organizationId, $batch);
        BroadcastRecipient::query()
            ->where('organization_id', $organizationId)
            ->where('batch_id', $batch->getKey())
            ->where('state', BroadcastRecipientState::Pending->value)
            ->update([
                'state' => BroadcastRecipientState::Failed->value,
                'lease_token' => null,
                'claimed_at' => null,
                'next_attempt_at' => null,
                'last_error_code' => $reason,
                'render_context' => [],
                'updated_at' => now(),
            ]);
        $batch->forceFill([
            'state' => 'failed',
            'lease_token' => null,
            'claimed_at' => null,
            'available_at' => null,
            'last_dispatch_error_code' => $reason,
            'completed_at' => now(),
        ])->save();
    }

    private function markAuthorizationRevoked(BroadcastRecipient $recipient): void
    {
        DB::transaction(function () use ($recipient): void {
            $campaign = BroadcastCampaign::query()
                ->where('organization_id', $recipient->organization_id)
                ->whereKey($recipient->campaign_id)
                ->lockForUpdate()
                ->first();
            $locked = BroadcastRecipient::query()
                ->where('organization_id', $recipient->organization_id)
                ->whereKey($recipient->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked !== null && $locked->state === BroadcastRecipientState::Claimed && $locked->lease_token === $recipient->lease_token) {
                $locked->forceFill([
                    'state' => BroadcastRecipientState::Failed,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'last_error_code' => 'authorization_revoked',
                    'render_context' => [],
                ])->save();
            }

            if ($campaign !== null && ! $campaign->state->isTerminal() && ! $this->authorization->creatorCanExecute($campaign)) {
                $this->cancelForRevokedAuthority($campaign);
            }
        });
    }

    private function cancelForRevokedAuthority(BroadcastCampaign $campaign): void
    {
        if ($campaign->state->isTerminal()) {
            return;
        }

        $batches = BroadcastBatch::query()
            ->where('organization_id', $campaign->organization_id)
            ->where('campaign_id', $campaign->getKey())
            ->where('snapshot_id', $campaign->audience_snapshot_id)
            ->whereIn('state', ['pending', 'claimed'])
            ->lockForUpdate()
            ->get();
        foreach ($batches as $batch) {
            $this->markClaimedRecipientsUnknown((int) $campaign->organization_id, $batch);
            BroadcastRecipient::query()
                ->where('organization_id', $campaign->organization_id)
                ->where('batch_id', $batch->getKey())
                ->where('state', BroadcastRecipientState::Pending->value)
                ->update([
                    'state' => BroadcastRecipientState::Failed->value,
                    'lease_token' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'last_error_code' => 'authorization_revoked',
                    'render_context' => [],
                    'updated_at' => now(),
                ]);
            $batch->forceFill([
                'state' => 'failed',
                'lease_token' => null,
                'claimed_at' => null,
                'available_at' => null,
                'last_dispatch_error_code' => 'authorization_revoked',
                'completed_at' => now(),
            ])->save();
        }
        $campaign->forceFill([
            'state' => BroadcastCampaignState::Cancelled,
            'cancelled_at' => now(),
            'next_dispatch_at' => null,
            'last_dispatch_error_code' => 'authorization_revoked',
        ])->save();
        $this->audit->handle(
            Organization::query()->findOrFail($campaign->organization_id),
            null,
            'broadcast.campaign.execution_blocked',
            BroadcastCampaign::class,
            (string) $campaign->getKey(),
            ['reason' => 'creator_authority_revoked'],
        );
    }

    private function reconcileCampaign(int $organizationId, int $campaignId): void
    {
        $campaign = BroadcastCampaign::query()
            ->where('organization_id', $organizationId)
            ->whereKey($campaignId)
            ->lockForUpdate()
            ->firstOrFail();
        if ($campaign->state->isTerminal()) {
            return;
        }

        $recipients = BroadcastRecipient::query()
            ->where('organization_id', $organizationId)
            ->where('campaign_id', $campaignId)
            ->where('snapshot_id', $campaign->audience_snapshot_id)
            ->where('kind', 'production');
        $delivered = (clone $recipients)->where('state', BroadcastRecipientState::Delivered->value)->count();
        $failed = (clone $recipients)->where('state', BroadcastRecipientState::Failed->value)->count();
        $suppressed = (clone $recipients)->where('state', BroadcastRecipientState::Suppressed->value)->count();
        $sent = (clone $recipients)->where('attempt_count', '>', 0)->count();
        $unfinished = BroadcastBatch::query()
            ->where('organization_id', $organizationId)
            ->where('campaign_id', $campaignId)
            ->where('snapshot_id', $campaign->audience_snapshot_id)
            ->whereIn('state', ['pending', 'claimed'])
            ->exists();
        $campaign->forceFill([
            'sent_count' => $sent,
            'delivered_count' => $delivered,
            'failed_count' => $failed,
            'suppressed_count' => $suppressed,
            'state' => $unfinished ? BroadcastCampaignState::Dispatching : BroadcastCampaignState::Completed,
            'completed_at' => $unfinished ? null : now(),
        ])->save();
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
