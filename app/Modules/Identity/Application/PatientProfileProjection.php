<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientProfile;

final class PatientProfileProjection
{
    /** @return array<string, mixed> */
    public function handle(PatientProfile $patient, Client $viewer): array
    {
        $isSubject = (int) $patient->linked_account_client_id === (int) $viewer->getKey();
        $isOwner = (int) $patient->owner_client_id === (int) $viewer->getKey();
        $relationship = match ($patient->relationship) {
            'SELF' => __('Вы'),
            'SPOUSE' => $patient->gender === 'MALE' ? __('Супруг') : __('Супруга'),
            'CHILD' => $patient->gender === 'MALE' ? __('Сын') : __('Дочь'),
            'PARENT' => $patient->gender === 'MALE' ? __('Отец') : __('Мать'),
            default => __('Член семьи'),
        };

        return [
            'id' => (int) $patient->getKey(), 'name' => $patient->clinicalClient->full_name ?: __('Пациент'),
            'relationship' => $patient->relationship, 'relationshipLabel' => $relationship,
            'age' => $patient->birth_date?->age, 'birthDate' => $patient->birth_date?->toDateString(),
            'linked' => $patient->linked_account_client_id !== null, 'revoked' => $patient->owner_revoked_at !== null,
            'canInvite' => $isOwner && $patient->relationship !== 'SELF' && $patient->isAdult()
                && ($patient->linked_account_client_id === null || $patient->owner_revoked_at !== null),
            'canBook' => $isSubject || ($isOwner && $patient->owner_revoked_at === null),
            'canRevoke' => ($isOwner || $isSubject) && $patient->relationship !== 'SELF' && $patient->owner_revoked_at === null,
            'canEdit' => $isOwner && $patient->relationship !== 'SELF' && $patient->linked_account_client_id === null && $patient->owner_revoked_at === null,
            'identity' => ['first_name' => $patient->first_name ?? '', 'last_name' => $patient->last_name ?? '',
                'middle_name' => $patient->middle_name ?? '', 'gender' => $patient->gender ?? '',
                'birth_date' => $patient->birth_date?->toDateString() ?? '', 'relationship' => $patient->relationship],
            'updateUrl' => route('portal.family.update', ['patientId' => $patient->getKey()]),
            'medicalUrl' => $isSubject ? route('portal.medical-profile', ['patient_profile_id' => $patient->getKey()]) : null,
            'bookingUrl' => route('portal.bookings.create', ['patient_profile_id' => $patient->getKey()]),
            'invitationUrl' => route('portal.family.invite', ['patientId' => $patient->getKey()]),
            'revokeUrl' => route('portal.family.revoke', ['patientId' => $patient->getKey()]),
        ];
    }
}
