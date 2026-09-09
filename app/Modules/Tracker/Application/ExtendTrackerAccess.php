<?php

namespace App\Modules\Tracker\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Tracker\Domain\Models\TrackerEntitlement;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExtendTrackerAccess
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client, CarbonImmutable $endsAt, string $reason): TrackerEntitlement
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Укажите причину продления.']);
        }

        return DB::transaction(function () use ($actor, $client, $endsAt, $reason, $organization): TrackerEntitlement {
            $entitlement = TrackerEntitlement::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('active', true)
                ->lockForUpdate()
                ->first();
            if (! $entitlement instanceof TrackerEntitlement) {
                throw ValidationException::withMessages(['client' => 'У клиента нет активного доступа к трекеру.']);
            }
            $startsAt = CarbonImmutable::parse((string) $entitlement->getRawOriginal('starts_at'));
            $currentEndsAt = CarbonImmutable::parse((string) $entitlement->getRawOriginal('ends_at'));
            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                throw ValidationException::withMessages(['ends_at' => 'Дата окончания должна быть позже начала доступа.']);
            }
            $entitlement->forceFill(['ends_at' => $currentEndsAt->greaterThan($endsAt) ? $currentEndsAt : $endsAt, 'reason' => trim($reason)])->save();
            $this->audit->handle($organization, $actor, 'tracker.access.extended', TrackerEntitlement::class, (string) $entitlement->getKey(), ['client_id' => $client->getKey()]);

            return $entitlement->refresh();
        });
    }
}
