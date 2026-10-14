<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class EnsureSelfPatientProfile
{
    public function __construct(private OrganizationContext $context) {}

    public function handle(Client $client): PatientProfile
    {
        if ((int) $client->organization_id !== $this->context->id()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($client): PatientProfile {
            $client = Client::query()->where('organization_id', $this->context->id())->whereKey($client->getKey())->lockForUpdate()->firstOrFail();
            $profile = PatientProfile::query()->where('organization_id', $this->context->id())->where('clinical_client_id', $client->getKey())->first();
            if ($profile !== null && $profile->relationship !== 'SELF') {
                return $profile;
            }
            $profile ??= new PatientProfile;
            $profile->forceFill([
                'organization_id' => $client->organization_id, 'owner_client_id' => $client->getKey(),
                'clinical_client_id' => $client->getKey(), 'linked_account_client_id' => $client->getKey(), 'relationship' => 'SELF',
                'first_name' => $client->first_name, 'last_name' => $client->last_name, 'middle_name' => $client->middle_name,
                'gender' => in_array($client->gender, ['MALE', 'FEMALE'], true) ? $client->gender : null, 'birth_date' => $client->birth_date,
            ]);
            if ($profile->isDirty()) {
                $profile->save();
            }

            return $profile;
        });
    }
}
