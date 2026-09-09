<?php

namespace App\Modules\Tracker\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Tracker\Domain\Models\TrackerEntitlement;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

final class ResolveTrackerAccess
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly GetTrackerSettings $settings,
    ) {}

    public function handle(Client $client, ?CarbonImmutable $at = null): TrackerAccessState
    {
        if ((int) $client->organization_id !== $this->context->id()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $now = ($at ?? CarbonImmutable::now('UTC'))->utc();
        $entitlement = TrackerEntitlement::query()
            ->where('organization_id', $this->context->id())
            ->where('client_id', $client->getKey())
            ->where('active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->with('planVersion')
            ->first();

        return new TrackerAccessState($this->settings->enabled(), $this->settings->freeMode(), $entitlement);
    }
}
