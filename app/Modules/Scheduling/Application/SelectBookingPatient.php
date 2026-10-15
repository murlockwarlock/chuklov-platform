<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Identity\Application\EnsureSelfPatientProfile;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class SelectBookingPatient
{
    public function __construct(private OrganizationContext $context, private EnsureSelfPatientProfile $selfProfiles, private OrganizationFeatureGate $features) {}

    public function handle(Client $customer, ?int $patientId): PatientProfile
    {
        if ((int) $customer->organization_id !== $this->context->id()) {
            throw new AuthorizationException;
        }
        if ($patientId === null) {
            return $this->selfProfiles->handle($customer);
        }
        $patient = PatientProfile::query()->where('organization_id', $this->context->id())->whereKey($patientId)->lockForUpdate()->firstOrFail();
        if ($patient->relationship !== 'SELF') {
            $this->features->authorize($this->context->organization(), OrganizationFeature::ClientRecords);
        }
        $ownsActiveRelationship = (int) $patient->owner_client_id === (int) $customer->getKey() && $patient->owner_revoked_at === null;
        $isLinkedSubject = (int) $patient->linked_account_client_id === (int) $customer->getKey();
        if (! $ownsActiveRelationship && ! $isLinkedSubject) {
            throw new AuthorizationException;
        }

        return $patient;
    }
}
