<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Integration\Domain\Enums\IntegrationEventStatus;
use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use App\Modules\Integration\Domain\Models\IntegrationEvent;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Domain\Models\ReferralCommercialEvidence;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ConsumeFinanceSettlementEvent
{
    public function __construct(
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(int $eventId): ?ReferralCommercialEvidence
    {
        $claim = $this->claim($eventId);

        if ($claim === null) {
            return $this->existingEvidence($eventId);
        }

        [$organizationId, $token] = $claim;

        try {
            $evidence = $this->validateAndRecord($eventId, $organizationId, $token);

            return $evidence;
        } catch (InvalidFinanceSettlementEvent $exception) {
            $this->markFailed($eventId, $organizationId, $token);

            return null;
        } catch (Throwable $exception) {
            $this->markRetryable($eventId, $organizationId, $token);

            throw $exception;
        }
    }

    /** @return array{0: int, 1: string}|null */
    private function claim(int $eventId): ?array
    {
        return DB::transaction(function () use ($eventId): ?array {
            $event = IntegrationEvent::query()->whereKey($eventId)->lockForUpdate()->first();

            if (! $event instanceof IntegrationEvent
                || $event->getRawOriginal('event_type') !== IntegrationEventType::FinanceObligationSettled->value) {
                return null;
            }

            if ($event->status === IntegrationEventStatus::Processed
                || $event->status === IntegrationEventStatus::Failed) {
                return null;
            }

            $staleAt = CarbonImmutable::now()->subSeconds((int) config('referrals.events.stale_after_seconds', 300));
            if ($event->status === IntegrationEventStatus::Processing
                && $event->processing_started_at !== null
                && $event->processing_started_at->greaterThan($staleAt)) {
                return null;
            }

            if ((int) $event->attempt_count >= (int) config('referrals.events.max_attempts', 5)) {
                $event->forceFill(['status' => IntegrationEventStatus::Failed])->save();

                return null;
            }

            $token = bin2hex(random_bytes(32));
            $event->forceFill([
                'status' => IntegrationEventStatus::Processing,
                'attempt_count' => (int) $event->attempt_count + 1,
                'processing_started_at' => now(),
                'processing_token' => $token,
                'updated_at' => now(),
            ])->save();

            return [(int) $event->organization_id, $token];
        });
    }

    private function validateAndRecord(int $eventId, int $organizationId, string $token): ReferralCommercialEvidence
    {
        $event = IntegrationEvent::query()
            ->where('organization_id', $organizationId)
            ->whereKey($eventId)
            ->firstOrFail();
        $payload = json_decode((string) $event->getRawOriginal('payload'), true);

        if ($event->processing_token !== $token
            || $event->status !== IntegrationEventStatus::Processing
            || $event->getRawOriginal('event_type') !== IntegrationEventType::FinanceObligationSettled->value
            || $event->aggregate_type !== 'financial_obligation'
            || ! is_array($payload)) {
            throw new InvalidFinanceSettlementEvent('The finance settlement event claim is invalid.');
        }

        $organizationPayload = $this->positiveInt($payload, 'organization_id');
        $obligationId = $this->positiveInt($payload, 'obligation_id');
        $clientId = $this->positiveInt($payload, 'client_id');
        $ledgerEntryId = $this->positiveInt($payload, 'ledger_entry_id');

        if ($organizationPayload !== $organizationId
            || (int) $event->aggregate_id !== $obligationId
            || ($payload['evidence_type'] ?? null) !== 'finance_obligation_settled'
            || ($payload['finance_status'] ?? null) !== 'settled') {
            throw new InvalidFinanceSettlementEvent('The finance settlement event payload is invalid.');
        }

        $obligation = FinancialObligation::query()
            ->where('organization_id', $organizationId)
            ->whereKey($obligationId)
            ->first();
        $ledgerEntry = FinancialLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->whereKey($ledgerEntryId)
            ->first();

        if (! $obligation instanceof FinancialObligation
            || ! $ledgerEntry instanceof FinancialLedgerEntry
            || (int) $obligation->client_id !== $clientId
            || (int) $ledgerEntry->obligation_id !== $obligation->getKey()) {
            throw new InvalidFinanceSettlementEvent('The finance settlement evidence does not match authoritative records.');
        }

        $reconciliation = $this->reconciliation->handle($organizationId, $obligationId, true);
        if (! $reconciliation->isSettled()) {
            throw new InvalidFinanceSettlementEvent('The financial obligation is not settled.');
        }

        return DB::transaction(function () use ($event, $organizationId, $token, $obligation, $ledgerEntry): ReferralCommercialEvidence {
            $lockedEvent = IntegrationEvent::query()
                ->where('organization_id', $organizationId)
                ->whereKey($event->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedEvent->processing_token !== $token
                || $lockedEvent->status !== IntegrationEventStatus::Processing) {
                return ReferralCommercialEvidence::query()
                    ->where('organization_id', $organizationId)
                    ->where('integration_event_id', $lockedEvent->getKey())
                    ->firstOrFail();
            }

            $existing = ReferralCommercialEvidence::query()
                ->where('organization_id', $organizationId)
                ->where('integration_event_id', $lockedEvent->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof ReferralCommercialEvidence) {
                $this->markProcessed($lockedEvent);

                return $existing;
            }

            $relationship = ReferralRelationship::query()
                ->where('organization_id', $organizationId)
                ->where('referred_client_id', $obligation->client_id)
                ->lockForUpdate()
                ->first();
            $organization = Organization::query()->findOrFail($organizationId);
            $evidence = new ReferralCommercialEvidence;
            $evidence->forceFill([
                'organization_id' => $organizationId,
                'integration_event_id' => $lockedEvent->getKey(),
                'referral_relationship_id' => $relationship?->getKey(),
                'referred_client_id' => $obligation->client_id,
                'financial_obligation_id' => $obligation->getKey(),
                'financial_ledger_entry_id' => $ledgerEntry->getKey(),
                'evidence_type' => 'finance_obligation_settled',
                'observation_source' => 'finance',
                'observed_at' => $lockedEvent->occurred_at,
            ]);
            $evidence->save();
            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'referral.commercial_evidence.observed',
                targetType: ReferralCommercialEvidence::class,
                targetId: (string) $evidence->getKey(),
                metadata: [
                    'relationship_id' => $relationship?->getKey(),
                    'referred_client_id' => $obligation->client_id,
                    'obligation_id' => $obligation->getKey(),
                    'ledger_entry_id' => $ledgerEntry->getKey(),
                    'evidence_type' => 'finance_obligation_settled',
                    'source' => 'finance',
                ],
            );
            $this->markProcessed($lockedEvent);

            return $evidence->refresh();
        });
    }

    private function existingEvidence(int $eventId): ?ReferralCommercialEvidence
    {
        return ReferralCommercialEvidence::query()
            ->where('integration_event_id', $eventId)
            ->first();
    }

    /** @param array<string, mixed> $payload */
    private function positiveInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new InvalidFinanceSettlementEvent('The finance settlement event identifier is invalid.');
    }

    private function markProcessed(IntegrationEvent $event): void
    {
        $event->forceFill([
            'status' => IntegrationEventStatus::Processed,
            'processed_at' => now(),
            'processing_started_at' => null,
            'processing_token' => null,
            'updated_at' => now(),
        ])->save();
    }

    private function markFailed(int $eventId, int $organizationId, string $token): void
    {
        IntegrationEvent::query()
            ->where('organization_id', $organizationId)
            ->whereKey($eventId)
            ->where('processing_token', $token)
            ->update([
                'status' => IntegrationEventStatus::Failed->value,
                'processing_started_at' => null,
                'processing_token' => null,
                'updated_at' => now(),
            ]);
    }

    private function markRetryable(int $eventId, int $organizationId, string $token): void
    {
        IntegrationEvent::query()
            ->where('organization_id', $organizationId)
            ->whereKey($eventId)
            ->where('processing_token', $token)
            ->update([
                'status' => IntegrationEventStatus::Retryable->value,
                'available_at' => now()->addSeconds((int) config('referrals.events.retry_after_seconds', 60)),
                'processing_started_at' => null,
                'processing_token' => null,
                'updated_at' => now(),
            ]);
    }
}
