<?php

namespace App\Modules\Channels\Application;

use App\Modules\Identity\Application\AuthenticateClientWithVerifiedChannel;
use App\Modules\Identity\Application\BindPatientInvitationToAccount;
use App\Modules\Identity\Application\VerifiedChannelIdentity;
use App\Modules\Identity\Domain\Models\PatientInvitation;
use App\Modules\Organizations\Application\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class PreparePatientTelegramInvitation
{
    public function __construct(private OrganizationContext $context, private AuthenticateClientWithVerifiedChannel $authenticate,
        private BindPatientInvitationToAccount $bind, private ResolveTelegramMiniAppEntry $entries) {}

    public function handle(string $token, VerifiedChannelIdentity $identity): string
    {
        if ($identity->channel !== 'telegram' || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            throw new AuthorizationException;
        }
        $invitation = PatientInvitation::query()->where('organization_id', $this->context->id())->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
        if ($invitation === null || CarbonImmutable::parse((string) $invitation->expires_at)->isPast()) {
            throw new AuthorizationException;
        }
        $account = $this->authenticate->handle($identity);
        $invitation = $this->bind->handle($token, $account);

        return $this->entries->launchUrl('family', ['patient_invitation_id' => (int) $invitation->getKey()]);
    }
}
