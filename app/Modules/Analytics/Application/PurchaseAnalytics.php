<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\Data\PurchaseAnalyticsData;
use App\Modules\Commerce\Domain\Models\Purchase;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Support\Facades\DB;

final class PurchaseAnalytics
{
    private const array Labels = [
        'service' => 'Разовые сеансы',
        'program' => 'Пакеты и программы',
        'gift_certificate' => 'Сертификаты',
        'physical_product' => 'Товары',
        'online_product' => 'Онлайн-продукты',
        'unknown' => 'Другие покупки',
    ];

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(User $actor, DashboardPeriod $period): PurchaseAnalyticsData
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewFinance);

        $rows = DB::table((new Purchase)->getTable().' as purchases')
            ->join('commerce_purchase_items as items', function ($join): void {
                $join
                    ->on('items.organization_id', '=', 'purchases.organization_id')
                    ->on('items.purchase_id', '=', 'purchases.id');
            })
            ->where('purchases.organization_id', (int) $organization->getKey())
            ->where('purchases.created_at', '>=', $period->startUtc)
            ->where('purchases.created_at', '<', $period->endUtc)
            ->select('items.product_snapshot', 'purchases.client_id')
            ->get();

        $aggregates = [];
        foreach ($rows as $row) {
            $snapshot = is_array($row->product_snapshot)
                ? $row->product_snapshot
                : json_decode((string) $row->product_snapshot, true);
            $key = is_array($snapshot) && is_string($snapshot['catalog_type'] ?? null)
                ? $snapshot['catalog_type']
                : 'unknown';
            if (! isset(self::Labels[$key])) {
                $key = 'unknown';
            }
            $aggregates[$key]['clients'][(int) $row->client_id] = true;
            $aggregates[$key]['count'] = ($aggregates[$key]['count'] ?? 0) + 1;
        }

        $types = [];
        foreach (self::Labels as $key => $label) {
            if (! isset($aggregates[$key])) {
                continue;
            }
            $types[] = [
                'key' => $key,
                'label' => $label,
                'count' => (int) $aggregates[$key]['count'],
                'client_count' => count($aggregates[$key]['clients']),
            ];
        }

        return new PurchaseAnalyticsData($types);
    }
}
