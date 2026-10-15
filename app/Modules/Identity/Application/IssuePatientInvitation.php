<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientInvitation;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Security\Application\RecordAuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

final readonly class IssuePatientInvitation
{
    public function __construct(private OrganizationContext $context, private PatientProfileAccess $access, private RecordAuditEvent $audit) {}

    public function handle(User|Client $actor, PatientProfile $patient, string $requestKey): PatientInvitation
    {
        $this->access->authorizeOwner($actor, $patient);
        Validator::make(['request_key' => $requestKey], ['request_key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $patient, $requestKey): PatientInvitation {
            $patient = PatientProfile::query()->where('organization_id', $this->context->id())->whereKey($patient->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorizeOwner($actor, $patient);
            $existing = PatientInvitation::query()->where('organization_id', $this->context->id())
                ->where('owner_client_id', $patient->owner_client_id)->where('request_key', $requestKey)->first();
            if ($existing !== null) {
                if ((int) $existing->patient_profile_id !== (int) $patient->getKey() || $existing->revoked_at !== null || CarbonImmutable::parse((string) $existing->expires_at)->isPast()) {
                    throw ValidationException::withMessages(['invitation' => __('Создайте новое приглашение.')]);
                }

                return $existing;
            }
            if ($patient->relationship === 'SELF' || ! $patient->isAdult()) {
                throw ValidationException::withMessages(['invitation' => __('Приглашение для самостоятельного аккаунта доступно только совершеннолетнему пациенту.')]);
            }
            $ttl = config('portal.patient_invitation_ttl_minutes');
            if (! is_int($ttl) || $ttl < 1 || $ttl > 43200) {
                throw new LogicException('Invalid patient invitation lifetime.');
            }
            PatientInvitation::query()->where('organization_id', $this->context->id())->where('patient_profile_id', $patient->getKey())
                ->whereNull('revoked_at')->whereNull('accepted_at')->update(['revoked_at' => now()]);
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $invitation = new PatientInvitation;
            $invitation->forceFill([
                'organization_id' => $this->context->id(), 'owner_client_id' => $patient->owner_client_id, 'patient_profile_id' => $patient->getKey(),
                'request_key' => $requestKey, 'token_hash' => hash('sha256', $token), 'encrypted_token' => $token, 'expires_at' => now()->addMinutes($ttl),
            ])->save();
            $this->audit->handle($this->context->organization(), $actor instanceof User ? $actor : null, 'patient.invitation.created', PatientInvitation::class,
                (string) $invitation->getKey(), ['patient_profile_id' => $patient->getKey()]);

            return $invitation;
        });
    }
}
