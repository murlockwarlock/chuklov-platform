<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final readonly class GetClientBalanceSummary
{
    private const MAX_CURRENCIES = 10;

    public function __construct(private FinanceAuthorization $authorization) {}

    /** @return list<array{currency: string, outstandingMinor: string}> */
    public function handle(User $actor, Client $client): array
    {
        $organization = $this->authorization->authorizeView($actor);
        $this->authorization->assertClientOwned($client);

        $applied = DB::table('financial_ledger_entries')
            ->select([
                'organization_id',
                'obligation_id',
                DB::raw('SUM(settlement_amount_minor) AS applied_minor'),
            ])
            ->where('organization_id', $organization->getKey())
            ->groupBy('organization_id', 'obligation_id');
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
                'financial_obligations.settlement_currency AS currency',
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
