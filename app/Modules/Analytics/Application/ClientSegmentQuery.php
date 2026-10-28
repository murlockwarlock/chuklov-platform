<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\Enums\ClientSegment;
use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class ClientSegmentQuery
{
    public function __construct(private readonly OrganizationContext $context) {}

    /** @return Builder<Client> */
    public function query(ClientSegment|string|null $segment = null): Builder
    {
        return $this->apply(
            Client::query()->where('organization_id', $this->context->id()),
            $segment,
        );
    }

    /** @param Builder<Client> $query
     * @return Builder<Client>
     */
    public function apply(Builder $query, ClientSegment|string|null $segment = null): Builder
    {
        $resolved = $this->resolve($segment);
        $completed = fn (Builder $booking): Builder => $booking
            ->whereColumn('bookings.organization_id', 'clients.organization_id')
            ->where('bookings.status', BookingStatus::Completed->value);
        $thresholds = app(ClientSegmentConfiguration::class)->values();

        return match ($resolved) {
            ClientSegment::All => $query,
            ClientSegment::NewClients => $query->where(
                'clients.created_at',
                '>=',
                CarbonImmutable::now('UTC')->subDays($thresholds['new_days']),
            ),
            ClientSegment::Regular => $query->whereHas(
                'bookings',
                $completed,
                '>=',
                $thresholds['regular_completed_visits'],
            ),
            ClientSegment::Dormant => $query
                ->whereHas('bookings', $completed)
                ->whereDoesntHave(
                    'bookings',
                    fn (Builder $booking): Builder => $completed($booking)->where(
                        'bookings.starts_at',
                        '>=',
                        CarbonImmutable::now('UTC')->subDays($thresholds['dormant_days']),
                    ),
                ),
            ClientSegment::NoCompletedVisits => $query->whereDoesntHave('bookings', $completed),
            ClientSegment::Restricted => $query->whereHas('activeBookingRestriction'),
            ClientSegment::Blacklist => $query->whereHas('activeBlacklistRestriction'),
            ClientSegment::WarmLead => $query
                ->whereHas('bookings', $completed)
                ->whereDoesntHave('purchases', fn (Builder $purchase): Builder => $purchase
                    ->whereColumn('commerce_purchases.organization_id', 'clients.organization_id')
                    ->where('commerce_purchases.status', PurchaseStatus::Paid->value)),
            ClientSegment::Sleeping => $query
                ->where('clients.created_at', '<', CarbonImmutable::now('UTC')->subDays($thresholds['sleeping_days']))
                ->whereDoesntHave(
                    'bookings',
                    fn (Builder $booking): Builder => $completed($booking)->where(
                        'bookings.starts_at',
                        '>=',
                        CarbonImmutable::now('UTC')->subDays($thresholds['sleeping_days']),
                    ),
                )
                ->whereDoesntHave(
                    'purchases',
                    fn (Builder $purchase): Builder => $purchase
                        ->whereColumn('commerce_purchases.organization_id', 'clients.organization_id')
                        ->where('commerce_purchases.status', PurchaseStatus::Paid->value)
                        ->where('commerce_purchases.created_at', '>=', CarbonImmutable::now('UTC')->subDays($thresholds['sleeping_days'])),
                ),
            ClientSegment::ColdLead => $query->whereHas('bookings', fn (Builder $booking): Builder => $booking
                ->whereColumn('bookings.organization_id', 'clients.organization_id')
                ->where('bookings.status', BookingStatus::NoShow->value)),
        };
    }

    /** @return array<string, int> */
    public function summary(?string $search = null): array
    {
        $query = Client::query()->where('organization_id', $this->context->id());

        if ($search !== null && trim($search) !== '') {
            app(ClientSearch::class)->apply($query, $search);
        }

        $summary = [];
        foreach (ClientSegment::cases() as $segment) {
            $summary[$segment->value] = (clone $this->apply(clone $query, $segment))->count();
        }

        return $summary;
    }

    private function resolve(ClientSegment|string|null $segment): ClientSegment
    {
        if ($segment instanceof ClientSegment) {
            return $segment;
        }

        if ($segment === null || $segment === '') {
            return ClientSegment::All;
        }

        $resolved = ClientSegment::tryFrom($segment);

        if (! $resolved instanceof ClientSegment) {
            throw new InvalidArgumentException('The client segment is invalid.');
        }

        return $resolved;
    }
}
