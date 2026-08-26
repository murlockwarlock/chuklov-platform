<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use Closure;
use Illuminate\Database\Eloquent\Builder;

final class BroadcastSegmentQuery
{
    public function __construct(private readonly SegmentDefinition $definitions) {}

    /**
     * @param  list<array{key: string, operator: string, value: mixed}>  $filters
     * @return Builder<Client>
     */
    public function build(int $organizationId, array $filters): Builder
    {
        $filters = $this->definitions->validate($filters);
        $query = Client::query()
            ->select(['clients.id', 'clients.organization_id', 'clients.full_name', 'clients.language'])
            ->where('clients.organization_id', $organizationId);

        foreach ($filters as $filter) {
            $values = $filter['operator'] === 'in' ? $filter['value'] : [$filter['value']];

            match ($filter['key']) {
                'tag' => $query->whereExists(fn ($sub) => $sub
                    ->from('broadcast_client_tags as bct')
                    ->whereColumn('bct.client_id', 'clients.id')
                    ->whereColumn('bct.organization_id', 'clients.organization_id')
                    ->whereIn('bct.tag', $values)),
                'b2b_role' => $query->whereExists(fn ($sub) => $sub
                    ->from('broadcast_client_profiles as bcp')
                    ->whereColumn('bcp.client_id', 'clients.id')
                    ->whereColumn('bcp.organization_id', 'clients.organization_id')
                    ->whereIn('bcp.b2b_role', $values)),
                'survey_completed' => $this->booleanExists($query, (bool) $filter['value'], fn ($sub) => $sub
                    ->from('survey_attempts as sa')
                    ->whereColumn('sa.client_id', 'clients.id')
                    ->whereColumn('sa.organization_id', 'clients.organization_id')
                    ->where('sa.status', SurveyAttemptStatus::Completed->value)),
                'visit_count' => $query->whereHas('bookings', fn ($booking) => $booking
                    ->whereColumn('bookings.organization_id', 'clients.organization_id')
                    ->where('bookings.status', BookingStatus::Completed->value), '>=', (int) $filter['value']),
                'booking_status' => $query->whereHas('bookings', fn ($booking) => $booking
                    ->whereColumn('bookings.organization_id', 'clients.organization_id')
                    ->whereIn('bookings.status', $values)),
                'last_visit' => $this->applyLastVisit($query, $filter['operator'], (string) $filter['value']),
                'no_future_booking' => $this->applyFutureBooking($query, (bool) $filter['value']),
                'referral_relationship' => $this->booleanRelation($query, 'referralRelationship', (bool) $filter['value'], fn ($relationship) => $relationship
                    ->whereColumn('referral_relationships.organization_id', 'clients.organization_id')),
                'attribution_source' => $query->whereHas('attribution', fn ($attribution) => $attribution
                    ->whereColumn('client_attributions.organization_id', 'clients.organization_id')
                    ->where(function ($source) use ($values): void {
                        $source->whereIn('client_attributions.source_type', $values)
                            ->orWhereIn('client_attributions.source', $values)
                            ->orWhereIn('client_attributions.utm_source', $values);
                    })),
                'language' => $query->whereIn('clients.language', $values),
                'verified_channel' => $query->whereHas('channelIdentities', fn ($identity) => $identity
                    ->whereColumn('client_channel_identities.organization_id', 'clients.organization_id')
                    ->where('client_channel_identities.channel', $filter['value'])
                    ->where('client_channel_identities.verification_status', ChannelIdentityStatus::Verified->value)),
                default => throw new \LogicException('Unsupported persisted broadcast segment filter.'),
            };
        }

        return $query->orderBy('clients.id');
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    private function applyLastVisit(Builder $query, string $operator, string $value): Builder
    {
        $completed = fn ($booking) => $booking
            ->whereColumn('bookings.organization_id', 'clients.organization_id')
            ->where('bookings.status', BookingStatus::Completed->value);

        if ($operator === 'before') {
            return $query
                ->whereHas('bookings', $completed)
                ->whereDoesntHave('bookings', fn ($booking) => $completed($booking)->where('bookings.starts_at', '>=', $value));
        }

        return $query->whereHas('bookings', fn ($booking) => $completed($booking)->where('bookings.starts_at', '>', $value));
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    private function applyFutureBooking(Builder $query, bool $hasNoFutureBooking): Builder
    {
        $future = fn ($booking) => $booking
            ->whereColumn('bookings.organization_id', 'clients.organization_id')
            ->whereIn('bookings.status', BookingStatus::qualifyingFutureValues())
            ->where('bookings.starts_at', '>', now());

        return $hasNoFutureBooking
            ? $query->whereDoesntHave('bookings', $future)
            : $query->whereHas('bookings', $future);
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    private function booleanRelation(Builder $query, string $relation, bool $exists, Closure $callback): Builder
    {
        return $exists
            ? $query->whereHas($relation, $callback)
            : $query->whereDoesntHave($relation, $callback);
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    private function booleanExists(Builder $query, bool $exists, Closure $callback): Builder
    {
        return $exists ? $query->whereExists($callback) : $query->whereNotExists($callback);
    }
}
