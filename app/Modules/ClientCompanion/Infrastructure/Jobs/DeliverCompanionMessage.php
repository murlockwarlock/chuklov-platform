<?php

namespace App\Modules\ClientCompanion\Infrastructure\Jobs;

use App\Modules\Channels\Domain\Contracts\MessagingChannel;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\ValueObjects\CompanionActionButton;
use App\Modules\Channels\Domain\ValueObjects\CompanionOutboundChunk;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\ClientCompanion\Application\Services\CompanionMessageBodyReader;
use App\Modules\ClientCompanion\Domain\Enums\CompanionDeliveryStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionSafeAction;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class DeliverCompanionMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $deliveryId,
        public readonly bool $reconcileOnly = false,
    ) {
        $this->onQueue('ai-companion-delivery');
    }

    public function handle(MessagingChannel $channel, CompanionMessageBodyReader $bodyReader): void
    {
        $token = (string) Str::uuid();
        $delivery = $this->claim($token);
        if ($this->reconcileOnly) {
            if ($delivery === null) {
                $this->scheduleActiveReconciliation();
            }

            return;
        }
        if (! $delivery instanceof CompanionDelivery || $delivery->conversationMessage === null) {
            $this->scheduleActiveReconciliation();

            return;
        }
        $this->scheduleReconciliation($delivery->processing_lease_expires_at);

        try {
            $body = $bodyReader->read($this->organizationId, $delivery->conversationMessage);
            $metadata = $delivery->conversationMessage->metadata ?? [];
            $buttons = $this->buttons($delivery, $metadata);
        } catch (Throwable) {
            [$retry, $nextDeliveryId] = $this->finalize($token, NotificationDeliveryResult::retryable('protected_message_unavailable'));
            $this->dispatchFollowUp($retry, $nextDeliveryId);

            return;
        }

        try {
            $result = $channel->sendCompanionChunk(new CompanionOutboundChunk(
                recipientExternalId: $delivery->recipient_external_id,
                semanticText: $body,
                chunkIndex: (int) $delivery->chunk_index,
                chunkCount: (int) $delivery->chunk_count,
                locale: (string) ($metadata['locale'] ?? 'en'),
                buttons: $buttons,
            ));
        } catch (Throwable) {
            $result = NotificationDeliveryResult::unknown('delivery_send_exception_unknown');
        }

        [$retry, $nextDeliveryId] = $this->finalize($token, $result);
        $this->dispatchFollowUp($retry, $nextDeliveryId);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<CompanionActionButton>
     */
    private function buttons(CompanionDelivery $delivery, array $metadata): array
    {
        if ((int) $delivery->chunk_index !== (int) $delivery->chunk_count - 1) {
            return [];
        }

        $buttons = [];
        $safeActions = array_values(array_filter(explode(',', (string) ($metadata['safe_actions'] ?? ''))));
        foreach ($safeActions as $safeAction) {
            $action = CompanionSafeAction::tryFrom($safeAction);
            if ($action === null) {
                continue;
            }
            $callback = match ($action) {
                CompanionSafeAction::RequestHuman => 'cc:human:'.$delivery->conversation_message_id,
                CompanionSafeAction::ReinspectRecentImage => 'cc:reinspect:'.$delivery->conversation_message_id,
                CompanionSafeAction::FeedbackHelpful => 'cc:feedback:helpful:'.$delivery->conversation_message_id,
                CompanionSafeAction::FeedbackNotHelpful => 'cc:feedback:not_helpful:'.$delivery->conversation_message_id,
                CompanionSafeAction::OpenPortal => null,
            };
            if ($action === CompanionSafeAction::OpenPortal) {
                $portalUrl = (string) config('app.url');
                if (preg_match('/^https:\/\//i', $portalUrl) === 1) {
                    $buttons[] = new CompanionActionButton($action->label((string) ($metadata['locale'] ?? 'en')), url: rtrim($portalUrl, '/'));
                }
            } elseif ($callback !== null) {
                $buttons[] = new CompanionActionButton($action->label((string) ($metadata['locale'] ?? 'en')), callbackData: $callback);
            }
        }

        return $buttons;
    }

    private function claim(string $token): ?CompanionDelivery
    {
        return DB::transaction(function () use ($token): ?CompanionDelivery {
            $delivery = CompanionDelivery::query()
                ->where('organization_id', $this->organizationId)
                ->whereKey($this->deliveryId)
                ->lockForUpdate()
                ->first();
            if (! $delivery instanceof CompanionDelivery
                || in_array($delivery->status, [CompanionDeliveryStatus::Delivered, CompanionDeliveryStatus::Uncertain], true)) {
                return null;
            }

            if ($delivery->status === CompanionDeliveryStatus::Processing) {
                if (! $delivery->leaseIsExpired()) {
                    return null;
                }

                $delivery->update([
                    'status' => CompanionDeliveryStatus::Uncertain,
                    'last_error_code' => 'delivery_lease_expired_unknown',
                    'processing_lease_token' => null,
                    'processing_lease_expires_at' => null,
                    'next_attempt_at' => null,
                    'uncertain_at' => now(),
                ]);

                return null;
            }

            $maxAttempts = $this->maxAttempts();
            if ($delivery->status === CompanionDeliveryStatus::Failed
                && ($delivery->next_attempt_at === null
                    || $delivery->next_attempt_at->isFuture()
                    || $delivery->attempt_count >= $maxAttempts)) {
                return null;
            }

            $previous = CompanionDelivery::query()
                ->where('organization_id', $this->organizationId)
                ->where('conversation_message_id', $delivery->conversation_message_id)
                ->where('chunk_index', '<', $delivery->chunk_index)
                ->orderByDesc('chunk_index')
                ->lockForUpdate()
                ->first();
            if ($previous instanceof CompanionDelivery && $previous->status !== CompanionDeliveryStatus::Delivered) {
                if ($previous->status === CompanionDeliveryStatus::Uncertain
                    || ($previous->status === CompanionDeliveryStatus::Failed
                        && ($previous->next_attempt_at === null || $previous->attempt_count >= $maxAttempts))) {
                    $delivery->update([
                        'status' => CompanionDeliveryStatus::Failed,
                        'last_error_code' => 'blocked_by_previous_delivery',
                        'next_attempt_at' => null,
                    ]);
                }

                return null;
            }

            $delivery->update([
                'status' => CompanionDeliveryStatus::Processing,
                'attempt_count' => $delivery->attempt_count + 1,
                'processing_lease_token' => $token,
                'processing_lease_expires_at' => now()->addSeconds((int) config('ai.companion.delivery_lease_seconds', 60)),
                'next_attempt_at' => null,
            ]);

            return $delivery->fresh(['conversationMessage', 'turn']);
        });
    }

    /** @return array{0: bool, 1: int|null} */
    private function finalize(string $token, NotificationDeliveryResult $result): array
    {
        return DB::transaction(function () use ($token, $result): array {
            $locked = CompanionDelivery::query()
                ->where('organization_id', $this->organizationId)
                ->whereKey($this->deliveryId)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof CompanionDelivery
                || $locked->processing_lease_token !== $token
                || $locked->status !== CompanionDeliveryStatus::Processing
                || $locked->leaseIsExpired()) {
                return [false, null];
            }

            $outcome = $result->outcome === NotificationDeliveryOutcome::InFlight
                ? NotificationDeliveryOutcome::Unknown
                : $result->outcome;

            $retry = false;
            if ($outcome === NotificationDeliveryOutcome::Delivered) {
                $locked->update([
                    'status' => CompanionDeliveryStatus::Delivered,
                    'provider_reference' => $result->providerReference,
                    'last_error_code' => null,
                    'processing_lease_token' => null,
                    'processing_lease_expires_at' => null,
                    'next_attempt_at' => null,
                    'delivered_at' => now(),
                ]);
            } elseif ($outcome === NotificationDeliveryOutcome::Unknown) {
                $locked->update([
                    'status' => CompanionDeliveryStatus::Uncertain,
                    'provider_reference' => $result->providerReference,
                    'last_error_code' => $result->errorCode ?? 'delivery_outcome_unknown',
                    'processing_lease_token' => null,
                    'processing_lease_expires_at' => null,
                    'next_attempt_at' => null,
                    'uncertain_at' => now(),
                ]);
            } else {
                $retry = $outcome === NotificationDeliveryOutcome::Retryable
                    && $locked->attempt_count < $this->maxAttempts();
                $locked->update([
                    'status' => CompanionDeliveryStatus::Failed,
                    'last_error_code' => $result->errorCode,
                    'processing_lease_token' => null,
                    'processing_lease_expires_at' => null,
                    'next_attempt_at' => $retry ? now()->addSeconds($this->retryDelaySeconds()) : null,
                ]);
            }

            $nextDeliveryId = null;
            $delivered = $outcome === NotificationDeliveryOutcome::Delivered;
            if ($delivered) {
                $nextDeliveryId = CompanionDelivery::query()
                    ->where('organization_id', $this->organizationId)
                    ->where('conversation_message_id', $locked->conversation_message_id)
                    ->where('chunk_index', (int) $locked->chunk_index + 1)
                    ->value('id');
            }

            return [$retry, $nextDeliveryId === null ? null : (int) $nextDeliveryId];
        });
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('ai.companion.delivery_max_attempts', 3));
    }

    private function retryDelaySeconds(): int
    {
        return max(1, (int) config('ai.companion.delivery_retry_after_seconds', 5));
    }

    private function dispatchFollowUp(bool $retry, ?int $nextDeliveryId): void
    {
        if ($retry) {
            self::dispatch($this->organizationId, $this->deliveryId)
                ->delay(now()->addSeconds($this->retryDelaySeconds()));
        }
        if ($nextDeliveryId !== null) {
            self::dispatch($this->organizationId, $nextDeliveryId);
        }
    }

    private function scheduleReconciliation(?CarbonInterface $expiresAt): void
    {
        self::dispatch($this->organizationId, $this->deliveryId, true)
            ->delay(($expiresAt ?? now()->addSeconds((int) config('ai.companion.delivery_lease_seconds', 60)))->addSecond());
    }

    private function scheduleActiveReconciliation(): void
    {
        $active = CompanionDelivery::query()
            ->where('organization_id', $this->organizationId)
            ->whereKey($this->deliveryId)
            ->where('status', CompanionDeliveryStatus::Processing)
            ->first();
        if (! $active instanceof CompanionDelivery) {
            return;
        }

        $this->scheduleReconciliation($active->processing_lease_expires_at);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['companion-delivery:'.$this->deliveryId, 'organization:'.$this->organizationId];
    }
}
