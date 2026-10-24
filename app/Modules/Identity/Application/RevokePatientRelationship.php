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
use Illuminate\Validation\ValidationException;

final readonly class RevokePatientRelationship
{
    public function __construct(private OrganizationContext $context, private PatientProfileAccess $access, private RecordAuditEvent $audit) {}

    public function handle(User|Client $actor, PatientProfile $patient): void
    {
        if ($actor instanceof Client && (int) $patient->linked_account_client_id === (int) $actor->getKey()) {
            $this->access->authorizeClinicalSubject($actor, $patient);
        } else {
            $this->access->authorizeOwner($actor, $patient);
        }
        DB::transaction(function () use ($actor, $patient): void {
            $patient = PatientProfile::query()->where('organization_id', $this->context->id())->whereKey($patient->getKey())->lockForUpdate()->firstOrFail();
            if ($actor instanceof User) {
                $this->access->authorizeOwner($actor, $patient);
            }
            if ($patient->relationship === 'SELF') {
                throw ValidationException::withMessages(['patient' => __('Основной профиль нельзя отвязать.')]);
            }
            if ($patient->owner_revoked_at !== null) {
                return;
            }
            if ($actor instanceof Client && (int) $patient->owner_client_id !== (int) $actor->getKey()
                && (int) $patient->linked_account_client_id !== (int) $actor->getKey()) {
                throw new AuthorizationException;
            }
            $patient->forceFill(['owner_revoked_at' => now(), 'linked_account_client_id' => null])->save();
            PatientInvitation::query()->where('organization_id', $this->context->id())->where('patient_profile_id', $patient->getKey())
                ->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->audit->handle($this->context->organization(), $actor instanceof User ? $actor : null,
                'patient.relationship.revoked', PatientProfile::class, (string) $patient->getKey(),
                ['patient_profile_id' => $patient->getKey(), 'actor_type' => $actor instanceof User ? 'staff' : 'client',
                    'actor_client_id' => $actor instanceof Client ? $actor->getKey() : null]);
        });
    }
}
