<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Enums\LegalDocumentStatus;
use App\Modules\Identity\Domain\Enums\PatientAccessGrantScope;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Identity\Domain\Models\PatientAccessGrant;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class GrantPatientAccess
{
    public function __construct(
        private OrganizationContext $context,
        private PatientProfileAccess $access,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(
        User|Client $actor,
        PatientProfile $patient,
        Client $grantee,
        int $legalDocumentId,
        string $requestKey,
        PatientAccessGrantScope $scope = PatientAccessGrantScope::MedicalRecords,
    ): PatientAccessGrant {
        $this->access->authorizeOwner($actor, $patient);
        $this->access->authorizeAccount($grantee);
        Validator::make(['request_key' => $requestKey], ['request_key' => ['required', 'uuid']])->validate();

        if ((int) $patient->owner_client_id === (int) $grantee->getKey()
            || $grantee->birth_date === null
            || $grantee->birth_date->isAfter(CarbonImmutable::today('UTC')->subYears(18))) {
            throw ValidationException::withMessages(['grantee' => __('Доступ можно выдать только другому совершеннолетнему клиенту.')]);
        }

        return DB::transaction(function () use ($actor, $patient, $grantee, $legalDocumentId, $requestKey, $scope): PatientAccessGrant {
            $lockedPatient = PatientProfile::query()
                ->where('organization_id', $this->context->id())
                ->whereKey($patient->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->access->authorizeOwner($actor, $lockedPatient);

            $lockedGrantee = Client::query()
                ->where('organization_id', $this->context->id())
                ->whereKey($grantee->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $document = LegalDocument::query()
                ->where('organization_id', $this->context->id())
                ->whereKey($legalDocumentId)
                ->where('status', LegalDocumentStatus::Published)
                ->where('document_type', 'delegated_medical_access')
                ->lockForUpdate()
                ->first();
            if (! $document instanceof LegalDocument) {
                throw ValidationException::withMessages(['legal_document' => __('Опубликуйте документ для делегированного медицинского доступа перед выдачей доступа.')]);
            }

            $existingRequest = PatientAccessGrant::query()
                ->where('organization_id', $this->context->id())
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();
            if ($existingRequest instanceof PatientAccessGrant) {
                if ((int) $existingRequest->patient_profile_id !== (int) $lockedPatient->getKey()
                    || (int) $existingRequest->grantee_client_id !== (int) $lockedGrantee->getKey()
                    || (string) $existingRequest->getRawOriginal('scope') !== $scope->value) {
                    throw ValidationException::withMessages(['request_key' => __('Этот запрос уже использован для другой связи.')]);
                }

                return $existingRequest;
            }

            $active = PatientAccessGrant::query()
                ->where('organization_id', $this->context->id())
                ->where('patient_profile_id', $lockedPatient->getKey())
                ->where('grantee_client_id', $lockedGrantee->getKey())
                ->where('scope', $scope)
                ->whereNull('revoked_at')
                ->first();
            if ($active instanceof PatientAccessGrant) {
                return $active;
            }

            $grant = new PatientAccessGrant;
            $grant->forceFill([
                'organization_id' => $this->context->id(),
                'patient_profile_id' => $lockedPatient->getKey(),
                'grantor_client_id' => $lockedPatient->owner_client_id,
                'grantee_client_id' => $lockedGrantee->getKey(),
                'legal_document_id' => $document->getKey(),
                'legal_document_version' => $document->version,
                'scope' => $scope,
                'request_key' => $requestKey,
                'granted_at' => now(),
            ])->save();

            $this->audit->handle(
                organization: $this->context->organization(),
                actor: $actor instanceof User ? $actor : null,
                action: 'patient.access.granted',
                targetType: PatientAccessGrant::class,
                targetId: (string) $grant->getKey(),
                metadata: [
                    'patient_profile_id' => $lockedPatient->getKey(),
                    'grantee_client_id' => $lockedGrantee->getKey(),
                    'scope' => $scope->value,
                    'legal_document_id' => $document->getKey(),
                    'legal_document_version' => $document->version,
                ],
            );

            return $grant->refresh();
        });
    }
}
