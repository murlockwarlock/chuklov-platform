<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientInvitation;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class UpdatePatientProfile
{
    public function __construct(private OrganizationContext $context, private PatientProfileAccess $access, private NormalizeClientDemographics $demographics, private RecordAuditEvent $audit) {}

    /** @param array<string, mixed> $attributes */
    public function handle(User|Client $actor, PatientProfile $patient, array $attributes): PatientProfile
    {
        $this->access->authorizeOwner($actor, $patient);
        $data = Validator::make($attributes, [
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'], 'gender' => ['required', 'in:MALE,FEMALE'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'relationship' => ['required', 'in:SPOUSE,CHILD,PARENT'],
        ])->validate();
        $personal = $this->demographics->handle(array_intersect_key($data, array_flip(NormalizeClientDemographics::FIELDS)));
        if (trim((string) $personal['first_name']) === '' || trim((string) $personal['last_name']) === '') {
            throw ValidationException::withMessages(['patient' => __('Заполните имя и фамилию пациента.')]);
        }

        return DB::transaction(function () use ($actor, $patient, $data, $personal): PatientProfile {
            $clinical = Client::query()->where('organization_id', $this->context->id())->whereKey($patient->clinical_client_id)->lockForUpdate()->firstOrFail();
            $patient = PatientProfile::query()->where('organization_id', $this->context->id())->whereKey($patient->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorizeOwner($actor, $patient);
            if ($patient->relationship === 'SELF' || $patient->owner_revoked_at !== null
                || ($actor instanceof Client && $patient->linked_account_client_id !== null)) {
                throw new AuthorizationException;
            }
            $patient->forceFill([...array_intersect_key($personal, array_flip(['first_name', 'last_name', 'middle_name', 'gender', 'birth_date'])),
                'relationship' => $data['relationship']]);
            $clinical->forceFill($personal);
            if (! $patient->isDirty() && ! $clinical->isDirty()) {
                return $patient;
            }
            $clinical->save();
            $patient->save();
            PatientInvitation::query()->where('organization_id', $this->context->id())->where('patient_profile_id', $patient->getKey())
                ->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->audit->handle($this->context->organization(), $actor instanceof User ? $actor : null, 'patient.updated',
                PatientProfile::class, (string) $patient->getKey(), ['owner_client_id' => $patient->owner_client_id]);

            return $patient;
        });
    }
}
