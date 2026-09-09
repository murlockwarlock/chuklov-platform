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

final class EndTrackerAccess
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client, string $reason): ?TrackerEntitlement
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Укажите причину завершения доступа.']);
        }

        return DB::transaction(function () use ($actor, $client, $reason, $organization): ?TrackerEntitlement {
            $entitlement = TrackerEntitlement::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('active', true)
                ->lockForUpdate()
                ->first();
            if (! $entitlement instanceof TrackerEntitlement) {
                return null;
            }
            $entitlement->forceFill(['active' => false, 'ended_at' => CarbonImmutable::now('UTC'), 'reason' => trim($reason)])->save();
            $this->audit->handle($organization, $actor, 'tracker.access.ended', TrackerEntitlement::class, (string) $entitlement->getKey(), ['client_id' => $client->getKey()]);

            return $entitlement->refresh();
        });
    }
}
