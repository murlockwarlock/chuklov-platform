<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationContext;

final readonly class ResolvePatientClinicalClient
{
    public function __construct(private OrganizationContext $context, private PatientProfileAccess $access) {}

    public function handle(Client $account, ?int $patientId = null): Client
    {
        $this->access->authorizeAccount($account);
        if ($patientId === null) {
            return $account;
        }
        $patient = PatientProfile::query()->where('organization_id', $this->context->id())->whereKey($patientId)->firstOrFail();
        $this->access->authorizeClinicalSubject($account, $patient);

        return Client::query()->where('organization_id', $this->context->id())->whereKey($patient->clinical_client_id)->firstOrFail();
    }
}
