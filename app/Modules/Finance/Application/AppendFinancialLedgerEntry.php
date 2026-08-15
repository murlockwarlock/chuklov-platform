<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\FinancialLedgerEntryData;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class AppendFinancialLedgerEntry
{
    public function handle(
        Organization|int $organization,
        FinancialObligation|int $obligation,
        FinancialLedgerEntryData $data,
    ): FinancialLedgerEntry {
        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;
        $obligationId = $obligation instanceof FinancialObligation ? (int) $obligation->getKey() : $obligation;
        $subject = FinancialObligation::query()
            ->where('organization_id', $organizationId)
            ->whereKey($obligationId)
            ->first();

        if ($subject === null) {
            throw (new ModelNotFoundException)->setModel(FinancialObligation::class, [$obligationId]);
        }

        $entry = new FinancialLedgerEntry;
        $entry->forceFill([
            'organization_id' => $organizationId,
            'obligation_id' => $obligationId,
            'entry_type' => $data->entryType->value,
            'source' => $data->source->value,
            'amount_minor' => $data->amountMinor,
            'currency' => $data->currency->value,
            'payment_amount_minor' => $data->paymentAmountMinor,
            'payment_currency' => $data->paymentCurrency->value,
            'base_amount_minor' => $data->baseAmountMinor,
            'base_currency' => $data->baseCurrency->value,
            'display_amount_minor' => $data->displayAmountMinor,
            'display_currency' => $data->displayCurrency->value,
            'settlement_amount_minor' => $data->settlementAmountMinor,
            'settlement_currency' => $data->settlementCurrency->value,
            'conversion_snapshot' => $data->conversionSnapshot,
            'payment_method' => $data->paymentMethod?->value,
            'occurred_at' => $data->occurredAt,
            'note' => $data->note,
            'actor_user_id' => $data->actorUserId,
            'provider_reference' => $data->providerReference,
            'idempotency_key' => $data->idempotencyKey,
            'corrects_ledger_entry_id' => $data->correctsLedgerEntryId,
        ]);
        $entry->save();

        return $entry->refresh();
    }
}
