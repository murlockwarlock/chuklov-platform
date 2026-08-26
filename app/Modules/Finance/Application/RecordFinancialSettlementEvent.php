<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Integration\Application\RecordIntegrationEvent;
use App\Modules\Integration\Domain\Enums\IntegrationEventType;
use App\Modules\Integration\Domain\ValueObjects\IntegrationEventData;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

final class RecordFinancialSettlementEvent
{
    public function __construct(
        private readonly ReconcileFinancialObligation $reconciliation,
        private readonly RecordIntegrationEvent $events,
    ) {}

    public function handle(
        FinancialObligation $obligation,
        FinancialLedgerEntry $ledgerEntry,
        DateTimeInterface $occurredAt,
    ): void {
        if ((int) $obligation->organization_id !== (int) $ledgerEntry->organization_id
            || (int) $ledgerEntry->obligation_id !== (int) $obligation->getKey()) {
            throw new RuntimeException('The settlement evidence does not belong to one obligation.');
        }

        $reconciliation = $this->reconciliation->handle(
            (int) $obligation->organization_id,
            (int) $obligation->getKey(),
            true,
        );

        if (! $reconciliation->isSettled()) {
            throw new RuntimeException('Only a settled financial obligation can emit settlement evidence.');
        }

        $organization = $obligation->organization()->firstOrFail();
        $occurred = Carbon::instance($occurredAt)->utc();

        $this->events->handle(
            organization: $organization,
            data: new IntegrationEventData(
                eventType: IntegrationEventType::FinanceObligationSettled,
                aggregateType: 'financial_obligation',
                aggregateId: (int) $obligation->getKey(),
                idempotencyKey: 'finance.obligation.settled:'.$organization->getKey().':'.$obligation->getKey(),
                payload: [
                    'organization_id' => (int) $organization->getKey(),
                    'obligation_id' => (int) $obligation->getKey(),
                    'client_id' => (int) $obligation->client_id,
                    'ledger_entry_id' => (int) $ledgerEntry->getKey(),
                    'finance_status' => $reconciliation->status->value,
                    'evidence_type' => 'finance_obligation_settled',
                ],
                occurredAt: $occurred,
            ),
        );
    }
}
