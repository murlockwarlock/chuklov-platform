<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Enums\PatientAccessGrantScope;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientAccessGrant;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class PatientProfileAccess
{
    public function __construct(private OrganizationContext $context, private OrganizationAuthorizer $authorizer, private OrganizationFeatureGate $features) {}

    public function authorizeAccount(Client $account): void
    {
        if ((int) $account->organization_id !== $this->context->id()) {
            throw new AuthorizationException;
        }
        $this->features->authorize($this->context->organization(), OrganizationFeature::ClientRecords);
    }

    public function authorizeOwner(User|Client $actor, PatientProfile $patient): void
    {
        if ((int) $patient->organization_id !== $this->context->id()) {
            throw new AuthorizationException;
        }
        if ($actor instanceof User) {
            $this->authorizer->authorize($actor, $this->context->organization(), OrganizationPermission::ManageClients);

            return;
        }
        $this->authorizeAccount($actor);
        if ((int) $patient->owner_client_id !== (int) $actor->getKey()) {
            throw new AuthorizationException;
        }
    }

    public function authorizeOwnerAccount(User|Client $actor, Client $account): void
    {
        $this->authorizeAccount($account);
        if ($actor instanceof User) {
            $this->authorizer->authorize($actor, $this->context->organization(), OrganizationPermission::ManageClients);

            return;
        }
        $this->authorizeAccount($actor);
        if ((int) $actor->getKey() !== (int) $account->getKey()) {
            throw new AuthorizationException;
        }
    }

    public function authorizeClinicalSubject(Client $actor, PatientProfile $patient): void
    {
        $this->authorizeAccount($actor);
        if (! $this->allowsClinicalSubject($actor, $patient)) {
            throw new AuthorizationException;
        }
    }

    public function allowsClinicalSubject(Client $actor, PatientProfile $patient): bool
    {
        if ((int) $patient->organization_id !== $this->context->id()
            || $patient->owner_revoked_at !== null) {
            return false;
        }
        if ((int) $patient->linked_account_client_id === (int) $actor->getKey()) {
            return true;
        }

        return PatientAccessGrant::query()
            ->where('organization_id', $this->context->id())
            ->where('patient_profile_id', $patient->getKey())
            ->where('grantee_client_id', $actor->getKey())
            ->where('scope', PatientAccessGrantScope::MedicalRecords)
            ->whereNull('revoked_at')
            ->exists();
    }

    public function authorizeClinicalClient(Client $actor, Client $subject): void
    {
        $this->authorizeAccount($actor);
        if ((int) $subject->organization_id !== $this->context->id()) {
            throw new AuthorizationException;
        }
        if ((int) $actor->getKey() === (int) $subject->getKey()) {
            return;
        }
        $patient = PatientProfile::query()->where('organization_id', $this->context->id())->where('clinical_client_id', $subject->getKey())->first();
        if ($patient === null) {
            throw new AuthorizationException;
        }
        $this->authorizeClinicalSubject($actor, $patient);
    }
}
