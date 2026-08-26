<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class BroadcastSegmentQuery
{
    /**
     * @param  list<array{key: string, operator: string, value: mixed}>  $filters
     * @return Builder<Client>
     */
    public function build(int $organizationId, array $filters): Builder
    {
        $query = Client::query()->where('clients.organization_id', $organizationId);

        foreach ($filters as $filter) {
            $values = $filter['operator'] === 'in' ? $filter['value'] : [$filter['value']];

            match ($filter['key']) {
                'tag' => $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('broadcast_client_tags as bct')->whereColumn('bct.client_id', 'clients.id')->whereColumn('bct.organization_id', 'clients.organization_id')->whereIn('bct.tag', $values)),
                'b2b_role' => $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('broadcast_client_profiles as bcp')->whereColumn('bcp.client_id', 'clients.id')->whereColumn('bcp.organization_id', 'clients.organization_id')->whereIn('bcp.b2b_role', $values)),
                'survey_completed' => $this->booleanExists($query, (bool) $filter['value'], fn ($sub) => $sub->selectRaw('1')->from('survey_attempts as sa')->whereColumn('sa.client_id', 'clients.id')->whereColumn('sa.organization_id', 'clients.organization_id')->where('sa.status', SurveyAttemptStatus::Completed->value)),
                'visit_count' => $query->whereRaw('(SELECT COUNT(*) FROM bookings b WHERE b.organization_id = clients.organization_id AND b.client_id = clients.id AND b.status = ?) >= ?', [BookingStatus::Completed->value, $filter['value']]),
                'booking_status' => $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('bookings as bs')->whereColumn('bs.client_id', 'clients.id')->whereColumn('bs.organization_id', 'clients.organization_id')->whereIn('bs.status', $values)),
                'last_visit' => $query->whereRaw('(SELECT MAX(bv.starts_at) FROM bookings bv WHERE bv.organization_id = clients.organization_id AND bv.client_id = clients.id AND bv.status = ?) '.($filter['operator'] === 'before' ? '<' : '>').' ?', [BookingStatus::Completed->value, $filter['value']]),
                'no_future_booking' => $this->booleanExists($query, ! (bool) $filter['value'], fn ($sub) => $sub->selectRaw('1')->from('bookings as bf')->whereColumn('bf.client_id', 'clients.id')->whereColumn('bf.organization_id', 'clients.organization_id')->whereIn('bf.status', BookingStatus::qualifyingFutureValues())->where('bf.starts_at', '>', now())),
                'referral_relationship' => $this->booleanExists($query, (bool) $filter['value'], fn ($sub) => $sub->selectRaw('1')->from('referral_relationships as rr')->whereColumn('rr.referred_client_id', 'clients.id')->whereColumn('rr.organization_id', 'clients.organization_id')),
                'attribution_source' => $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('client_attributions as ca')->whereColumn('ca.client_id', 'clients.id')->whereColumn('ca.organization_id', 'clients.organization_id')->where(function ($source) use ($values): void {
                    $source->whereIn('ca.source_type', $values)->orWhereIn('ca.source', $values)->orWhereIn('ca.utm_source', $values);
                })),
                'language' => $query->whereIn('clients.language', $values),
                'verified_channel' => $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('client_channel_identities as ci')->whereColumn('ci.client_id', 'clients.id')->whereColumn('ci.organization_id', 'clients.organization_id')->where('ci.channel', $filter['value'])->where('ci.verification_status', ChannelIdentityStatus::Verified->value)),
                default => $query,
            };
        }

        return $query->orderBy('clients.id');
    }

    /**
     * @param  Builder<Client>  $query
     * @param  Closure(QueryBuilder): QueryBuilder  $callback
     * @return Builder<Client>
     */
    private function booleanExists(Builder $query, bool $exists, Closure $callback): Builder
    {
        return $exists ? $query->whereExists($callback) : $query->whereNotExists($callback);
    }
}
