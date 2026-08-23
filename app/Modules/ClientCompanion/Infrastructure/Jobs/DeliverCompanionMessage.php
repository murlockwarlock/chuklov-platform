<?php

namespace App\Modules\ClientCompanion\Infrastructure\Jobs;

use App\Modules\Channels\Domain\Contracts\MessagingChannel;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\ValueObjects\CompanionActionButton;
use App\Modules\Channels\Domain\ValueObjects\CompanionOutboundChunk;
use App\Modules\ClientCompanion\Application\Services\CompanionMessageBodyReader;
use App\Modules\ClientCompanion\Domain\Enums\CompanionDeliveryStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionSafeAction;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DeliverCompanionMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $organizationId, public readonly int $deliveryId)
    {
        $this->onQueue('ai-companion-delivery');
    }

    public function handle(MessagingChannel $channel, CompanionMessageBodyReader $bodyReader): void
    {
        $token = (string) Str::uuid();
        $delivery = DB::transaction(function () use ($token): ?CompanionDelivery {
            $delivery = CompanionDelivery::query()
                ->where('organization_id', $this->organizationId)
                ->whereKey($this->deliveryId)
                ->lockForUpdate()
                ->first();

            if ($delivery === null || $delivery->status === CompanionDeliveryStatus::Delivered) {
                return null;
            }

            if ($delivery->status === CompanionDeliveryStatus::Processing && ! $delivery->leaseIsExpired()) {
                return null;
            }

            $delivery->update([
                'status' => CompanionDeliveryStatus::Processing,
                'attempt_count' => $delivery->attempt_count + 1,
                'processing_lease_token' => $token,
                'processing_lease_expires_at' => now()->addSeconds((int) config('ai.companion.delivery_lease_seconds', 60)),
            ]);

            return $delivery->fresh(['conversationMessage', 'turn']);
        });

        if (! $delivery instanceof CompanionDelivery || $delivery->conversationMessage === null) {
            return;
        }

        $body = $bodyReader->read($this->organizationId, $delivery->conversationMessage);
        $metadata = $delivery->conversationMessage->metadata ?? [];
        $buttons = [];
        if ((int) $delivery->chunk_index === (int) $delivery->chunk_count - 1) {
            $safeActions = array_values(array_filter(explode(',', (string) ($metadata['safe_actions'] ?? ''))));
            foreach ($safeActions as $safeAction) {
                $action = CompanionSafeAction::tryFrom($safeAction);
                if ($action === null) {
                    continue;
                }
                $callback = match ($action) {
                    CompanionSafeAction::RequestHuman => 'cc:human:'.$delivery->conversation_message_id,
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
        }
        $result = $channel->sendCompanionChunk(new CompanionOutboundChunk(
            recipientExternalId: $delivery->recipient_external_id,
            semanticText: $body,
            chunkIndex: (int) $delivery->chunk_index,
            chunkCount: (int) $delivery->chunk_count,
            locale: (string) ($metadata['locale'] ?? 'en'),
            buttons: $buttons,
        ));

        DB::transaction(function () use ($delivery, $result, $token): void {
            $locked = CompanionDelivery::query()
                ->where('organization_id', $this->organizationId)
                ->whereKey($delivery->getKey())
                ->lockForUpdate()
                ->first();
            if ($locked === null || $locked->processing_lease_token !== $token || $locked->status === CompanionDeliveryStatus::Delivered) {
                return;
            }

            if ($result->outcome === NotificationDeliveryOutcome::Delivered) {
                $locked->update([
                    'status' => CompanionDeliveryStatus::Delivered,
                    'provider_reference' => $result->providerReference,
                    'last_error_code' => null,
                    'processing_lease_token' => null,
                    'processing_lease_expires_at' => null,
                    'delivered_at' => now(),
                ]);

                return;
            }

            $locked->update([
                'status' => CompanionDeliveryStatus::Failed,
                'last_error_code' => $result->errorCode,
                'processing_lease_token' => null,
                'processing_lease_expires_at' => null,
                'next_attempt_at' => now()->addSeconds(5),
            ]);
        });

        if ($result->outcome === NotificationDeliveryOutcome::Retryable) {
            throw new \RuntimeException('Companion delivery will be retried.');
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['companion-delivery:'.$this->deliveryId, 'organization:'.$this->organizationId];
    }
}
