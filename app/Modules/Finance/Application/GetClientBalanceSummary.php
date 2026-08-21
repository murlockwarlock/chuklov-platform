<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final readonly class GetClientBalanceSummary
{
    private const MAX_CURRENCIES = 10;

    public function __construct(
        private FinanceAuthorization $authorization,
        private FinancialReconciliationProjection $projection,
    ) {}

    /** @return list<array{currency: string, outstandingMinor: string}>|null */
    public function handle(User $actor, Client $client): ?array
    {
        $organization = $this->authorization->authorizeView($actor);
        $this->authorization->assertClientOwned($client);
        $obligations = FinancialObligation::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey());

        if ($this->projection->hasInvalidReconciliation($obligations)) {
            return null;
        }

        $applied = DB::table('financial_ledger_entries as ledger_entries')
            ->join('financial_obligations as applied_obligations', function (JoinClause $join): void {
                $join
                    ->on('ledger_entries.organization_id', '=', 'applied_obligations.organization_id')
                    ->on('ledger_entries.obligation_id', '=', 'applied_obligations.id');
            })
            ->select([
                'ledger_entries.organization_id',
                'ledger_entries.obligation_id',
                DB::raw('SUM(ledger_entries.settlement_amount_minor) AS applied_minor'),
            ])
            ->where('ledger_entries.organization_id', $organization->getKey())
            ->where('applied_obligations.organization_id', $organization->getKey())
            ->where('applied_obligations.client_id', $client->getKey())
            ->groupBy('ledger_entries.organization_id', 'ledger_entries.obligation_id');
        $summary = DB::query()
            ->from('financial_obligations')
            ->leftJoinSub($applied, 'ledger', function (JoinClause $join): void {
                $join
                    ->on('financial_obligations.organization_id', '=', 'ledger.organization_id')
                    ->on('financial_obligations.id', '=', 'ledger.obligation_id');
            })
            ->where('financial_obligations.organization_id', $organization->getKey())
            ->where('financial_obligations.client_id', $client->getKey())
            ->select([
                DB::raw('financial_obligations.settlement_currency AS currency'),
                DB::raw('SUM(financial_obligations.settlement_amount_minor - COALESCE(ledger.applied_minor, 0)) AS outstanding_minor'),
            ])
            ->groupBy('financial_obligations.settlement_currency')
            ->havingRaw('SUM(financial_obligations.settlement_amount_minor - COALESCE(ledger.applied_minor, 0)) > 0')
            ->orderByDesc('outstanding_minor')
            ->limit(self::MAX_CURRENCIES)
            ->get()
            ->map(static fn (object $row): array => [
                'currency' => (string) $row->currency,
                'outstandingMinor' => (string) $row->outstanding_minor,
            ])
            ->all();

        return array_values($summary);
    }
}
