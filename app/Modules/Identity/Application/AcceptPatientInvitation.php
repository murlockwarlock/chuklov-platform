<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientInvitation;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AcceptPatientInvitation
{
    public function __construct(
        private OrganizationContext $context,
        private PatientProfileAccess $access,
        private RecordAuditEvent $audit,
        private MaterializeScenarioEvent $scenarioEvents,
    ) {}

    public function handle(Client $actor, int $invitationId, bool $confirmIdentity): PatientProfile
    {
        $this->access->authorizeAccount($actor);
        if (! $confirmIdentity) {
            throw ValidationException::withMessages(['confirm_identity' => __('Подтвердите, что указанные данные относятся к вам.')]);
        }

        return DB::transaction(function () use ($actor, $invitationId): PatientProfile {
            $actor = Client::query()->where('organization_id', $this->context->id())->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorizeAccount($actor);
            $candidate = PatientInvitation::query()->where('organization_id', $this->context->id())->whereKey($invitationId)->firstOrFail();
            $patient = PatientProfile::query()->where('organization_id', $this->context->id())->whereKey($candidate->patient_profile_id)->lockForUpdate()->firstOrFail();
            $invitation = PatientInvitation::query()->where('organization_id', $this->context->id())->whereKey($invitationId)->lockForUpdate()->firstOrFail();
            if ((int) $invitation->intended_client_id !== (int) $actor->getKey() || $invitation->revoked_at !== null
                || ! $patient->isAdult() || ($patient->linked_account_client_id !== null && (int) $patient->linked_account_client_id !== (int) $actor->getKey())) {
                throw new AuthorizationException;
            }
            if ($invitation->accepted_at !== null) {
                if ((int) $invitation->accepted_by_client_id !== (int) $actor->getKey()) {
                    throw new AuthorizationException;
                }

                return $patient;
            }
            if ($invitation->expires_at->isPast()) {
                throw ValidationException::withMessages(['invitation' => __('Приглашение устарело. Попросите прислать новое.')]);
            }
            if ($actor->birth_date === null || $actor->birth_date->toDateString() !== $patient->birth_date->toDateString()
                || mb_strtolower(trim((string) $actor->first_name)) !== mb_strtolower(trim((string) $patient->first_name))
                || mb_strtolower(trim((string) $actor->last_name)) !== mb_strtolower(trim((string) $patient->last_name))) {
                throw ValidationException::withMessages(['invitation' => __('Личные данные не совпадают с приглашением. Проверьте свой профиль и данные пациента.')]);
            }
            $patient->forceFill(['linked_account_client_id' => $actor->getKey(), 'owner_revoked_at' => null])->save();
            $invitation->forceFill(['accepted_at' => now(), 'accepted_by_client_id' => $actor->getKey()])->save();
            $this->scenarioEvents->handleForLinkedPatient(
                organizationId: (int) $patient->organization_id,
                patientProfileId: (int) $patient->getKey(),
                clientId: (int) $actor->getKey(),
            );
            $this->audit->handle($this->context->organization(), null, 'patient.invitation.accepted', PatientInvitation::class,
                (string) $invitation->getKey(), ['patient_profile_id' => $patient->getKey(), 'account_client_id' => $actor->getKey()]);

            return $patient;
        });
    }
}
