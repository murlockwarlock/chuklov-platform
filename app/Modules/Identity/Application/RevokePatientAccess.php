<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientAccessGrant;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class RevokePatientAccess
{
    public function __construct(
        private OrganizationContext $context,
        private PatientProfileAccess $access,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User|Client $actor, PatientAccessGrant $grant): void
    {
        $grant = PatientAccessGrant::query()
            ->where('organization_id', $this->context->id())
            ->whereKey($grant->getKey())
            ->with('patient')
            ->firstOrFail();
        $patient = $grant->patient;
        if (! $patient instanceof PatientProfile) {
            throw new AuthorizationException;
        }
        if ($actor instanceof User) {
            $this->access->authorizeOwner($actor, $patient);
        } elseif ((int) $grant->grantee_client_id !== (int) $actor->getKey()) {
            $this->access->authorizeOwner($actor, $patient);
        }

        DB::transaction(function () use ($actor, $grant): void {
            $locked = PatientAccessGrant::query()
                ->where('organization_id', $this->context->id())
                ->whereKey($grant->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->revoked_at !== null) {
                return;
            }
            $patient = $locked->patient()->where('organization_id', $this->context->id())->firstOrFail();
            if ($actor instanceof User) {
                $this->access->authorizeOwner($actor, $patient);
            } elseif ((int) $locked->grantee_client_id !== (int) $actor->getKey()
                && (int) $locked->grantor_client_id !== (int) $actor->getKey()) {
                throw new AuthorizationException;
            }
            $locked->forceFill([
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor instanceof User ? $actor->getKey() : null,
            ])->save();
            $this->audit->handle(
                organization: $this->context->organization(),
                actor: $actor instanceof User ? $actor : null,
                action: 'patient.access.revoked',
                targetType: PatientAccessGrant::class,
                targetId: (string) $locked->getKey(),
                metadata: [
                    'patient_profile_id' => $locked->patient_profile_id,
                    'grantee_client_id' => $locked->grantee_client_id,
                    'scope' => $locked->scope->value,
                ],
            );
        });
    }
}
