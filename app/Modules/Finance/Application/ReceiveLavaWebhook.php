<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\PaymentGatewayEventStatus;
use App\Modules\Finance\Domain\Enums\ProviderVerificationStatus;
use App\Modules\Finance\Domain\Models\PaymentGatewayEvent;
use App\Modules\Finance\Domain\Services\PaymentGatewayEventIdentity;
use App\Modules\Finance\Infrastructure\Lava\LavaWebhookParser;
use Illuminate\Support\Facades\DB;

final class ReceiveLavaWebhook
{
    public function __construct(private readonly LavaWebhookParser $parser) {}

    public function handle(int $organizationId, array $payload): PaymentGatewayEvent
    {
        $event = $this->parser->parse($payload);
        $payloadHash = PaymentGatewayEventIdentity::payloadHash($payload);

        return DB::transaction(function () use ($organizationId, $event, $payloadHash): PaymentGatewayEvent {
            $existing = PaymentGatewayEvent::query()
                ->where('organization_id', $organizationId)
                ->where('gateway', 'lava')
                ->where('provider_event_key', $event->providerEventKey)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof PaymentGatewayEvent) {
                if ($existing->payload_hash !== $payloadHash) {
                    $existing->forceFill([
                        'processing_status' => PaymentGatewayEventStatus::ReconciliationRequired->value,
                        'reconciliation_reason' => 'provider_event_key_payload_conflict',
                        'last_error' => 'The same Lava event key arrived with a different payload.',
                    ])->save();
                }

                return $existing->refresh();
            }

            $status = $event->canAutomaticLink
                ? PaymentGatewayEventStatus::PendingLink
                : PaymentGatewayEventStatus::ReconciliationRequired;
            DB::table('payment_gateway_events')->insertOrIgnore([
                'organization_id' => $organizationId,
                'gateway_transaction_id' => null,
                'gateway' => 'lava',
                'event_type' => $event->normalizedType->value,
                'provider_event_id' => $event->providerEventId,
                'provider_event_key' => $event->providerEventKey,
                'provider_reference' => $event->providerReference,
                'verification_status' => ProviderVerificationStatus::Verified->value,
                'processing_status' => $status->value,
                'attempt_count' => 0,
                'amount_minor' => $event->amountMinor,
                'currency' => $event->currency?->value,
                'payload_hash' => $payloadHash,
                'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
                'next_attempt_at' => $status === PaymentGatewayEventStatus::PendingLink ? now() : null,
                'reconciliation_reason' => $status === PaymentGatewayEventStatus::ReconciliationRequired
                    ? 'manual_reconciliation_required'
                    : null,
                'created_at' => now(),
            ]);

            $inbox = PaymentGatewayEvent::query()
                ->where('organization_id', $organizationId)
                ->where('gateway', 'lava')
                ->where('provider_event_key', $event->providerEventKey)
                ->lockForUpdate()
                ->firstOrFail();

            if ($inbox->payload_hash !== $payloadHash) {
                $inbox->forceFill([
                    'processing_status' => PaymentGatewayEventStatus::ReconciliationRequired->value,
                    'reconciliation_reason' => 'provider_event_key_payload_conflict',
                    'last_error' => 'The same Lava event key arrived with a different payload.',
                ])->save();
            }

            return $inbox->refresh();
        });
    }
}
