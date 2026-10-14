<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientInvitation;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class BindPatientInvitationToAccount
{
    public function __construct(private OrganizationContext $context, private PatientProfileAccess $access) {}

    public function handle(string $token, Client $account): PatientInvitation
    {
        $this->access->authorizeAccount($account);
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            throw new AuthorizationException;
        }
        $id = PatientInvitation::query()->where('organization_id', $this->context->id())->where('token_hash', hash('sha256', $token))->value('id');
        if ($id === null) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($id, $account): PatientInvitation {
            $candidate = PatientInvitation::query()->where('organization_id', $this->context->id())->whereKey($id)->firstOrFail();
            $patient = PatientProfile::query()->where('organization_id', $this->context->id())->whereKey($candidate->patient_profile_id)->lockForUpdate()->firstOrFail();
            $invitation = PatientInvitation::query()->where('organization_id', $this->context->id())->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($invitation->revoked_at !== null || CarbonImmutable::parse((string) $invitation->expires_at)->isPast() || ! $patient->isAdult()
                || (int) $patient->owner_client_id === (int) $account->getKey()
                || ($invitation->intended_client_id !== null && (int) $invitation->intended_client_id !== (int) $account->getKey())
                || ($patient->linked_account_client_id !== null && (int) $patient->linked_account_client_id !== (int) $account->getKey())) {
                throw new AuthorizationException;
            }
            if ($invitation->intended_client_id === null) {
                $invitation->forceFill(['intended_client_id' => $account->getKey()])->save();
            }

            return $invitation;
        });
    }
}
