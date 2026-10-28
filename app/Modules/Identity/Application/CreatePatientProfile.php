<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class CreatePatientProfile
{
    public function __construct(private OrganizationContext $context, private PatientProfileAccess $access, private RecordAuditEvent $audit, private NormalizeClientDemographics $demographics) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Client $account, array $attributes, string $creationKey, ?User $staffActor = null): PatientProfile
    {
        $this->access->authorizeOwnerAccount($staffActor ?? $account, $account);
        $data = Validator::make([...$attributes, 'creation_key' => $creationKey], [
            'creation_key' => ['required', 'uuid'],
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'], 'gender' => ['required', 'in:MALE,FEMALE'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'relationship' => ['required', 'in:SPOUSE,CHILD,PARENT'],
        ])->validate();
        foreach (['first_name', 'last_name', 'middle_name'] as $field) {
            $data[$field] = isset($data[$field]) ? trim($data[$field]) : null;
            if ($field !== 'middle_name' && $data[$field] === '') {
                throw ValidationException::withMessages([$field => __('Заполните имя и фамилию пациента.')]);
            }
        }
        $demographics = $this->demographics->handle(array_intersect_key($data, array_flip(NormalizeClientDemographics::FIELDS)));
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($account, $data, $creationKey, $hash, $demographics, $staffActor): PatientProfile {
            $account = Client::query()->where('organization_id', $this->context->id())->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorizeOwnerAccount($staffActor ?? $account, $account);
            $existing = PatientProfile::query()->where('organization_id', $this->context->id())
                ->where('owner_client_id', $account->getKey())->where('creation_key', $creationKey)->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->creation_hash, $hash)) {
                    throw ValidationException::withMessages(['patient' => __('Данные пациента изменились. Подтвердите их заново.')]);
                }

                return $existing;
            }
            $clinicalClient = new Client;
            $clinicalClient->forceFill([
                'organization_id' => $this->context->id(), ...$demographics,
                'language' => $account->language ?? 'ru', 'timezone' => $account->timezone ?? $this->context->defaultTimezone(),
                'timezone_source' => 'manual', 'lead_source' => 'patient_profile',
            ])->save();
            $patient = new PatientProfile;
            $patient->forceFill([
                'organization_id' => $this->context->id(), 'owner_client_id' => $account->getKey(), 'clinical_client_id' => $clinicalClient->getKey(),
                'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'middle_name' => $data['middle_name'],
                'gender' => $data['gender'], 'birth_date' => $data['birth_date'], 'relationship' => $data['relationship'],
                'creation_key' => $creationKey, 'creation_hash' => $hash,
            ])->save();
            $this->audit->handle($this->context->organization(), $staffActor, 'patient.created', PatientProfile::class, (string) $patient->getKey(), ['owner_client_id' => $account->getKey()]);

            return $patient;
        });
    }
}
