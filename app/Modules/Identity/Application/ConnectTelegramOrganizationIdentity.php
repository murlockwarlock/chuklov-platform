<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Identity\Domain\Models\OrganizationChannelLinkToken;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ConnectTelegramOrganizationIdentity
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(string $token, VerifiedChannelIdentity $verifiedIdentity): void
    {
        if ($verifiedIdentity->channel !== 'telegram'
            || trim($verifiedIdentity->externalId) === ''
            || mb_strlen($verifiedIdentity->externalId) > 191) {
            throw new AuthorizationException('The Telegram identity evidence is invalid.');
        }

        $token = trim($token);
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token) !== 1) {
            throw new InvalidTelegramLinkToken('The Telegram connection token is invalid.');
        }

        try {
            DB::transaction(function () use ($token, $verifiedIdentity): void {
                $linkToken = OrganizationChannelLinkToken::query()
                    ->where('token_hash', hash('sha256', $token))
                    ->where('channel', 'telegram')
                    ->where('flow', 'crm.telegram.connect')
                    ->lockForUpdate()
                    ->first();

                if (! $linkToken instanceof OrganizationChannelLinkToken
                    || $linkToken->consumed_at !== null
                    || $linkToken->expires_at->isPast()) {
                    throw new InvalidTelegramLinkToken('The Telegram connection token is invalid or expired.');
                }

                $membership = OrganizationMembership::query()
                    ->where('organization_id', $linkToken->organization_id)
                    ->where('user_id', $linkToken->user_id)
                    ->active()
                    ->lockForUpdate()
                    ->first();
                if ($membership === null) {
                    throw new AuthorizationException('The staff member is no longer active in this organization.');
                }

                $identity = OrganizationChannelIdentity::query()
                    ->where('organization_id', $linkToken->organization_id)
                    ->where('channel', 'telegram')
                    ->where('external_id', $verifiedIdentity->externalId)
                    ->lockForUpdate()
                    ->first();

                if ($identity instanceof OrganizationChannelIdentity
                    && (int) $identity->user_id !== (int) $linkToken->user_id) {
                    throw new AuthorizationException('The Telegram identity is already linked to another staff member.');
                }

                if (! $identity instanceof OrganizationChannelIdentity) {
                    $identity = new OrganizationChannelIdentity;
                    $identity->forceFill([
                        'organization_id' => $linkToken->organization_id,
                        'user_id' => $linkToken->user_id,
                        'channel' => 'telegram',
                        'external_id' => $verifiedIdentity->externalId,
                    ]);
                }

                if ($identity->verification_status === ChannelIdentityStatus::Revoked) {
                    throw new AuthorizationException('The Telegram identity is revoked.');
                }

                $identity->forceFill([
                    'verification_status' => ChannelIdentityStatus::Verified,
                    'verification_method' => 'telegram_crm_link',
                    'verified_at' => now(),
                ])->save();

                $organization = $linkToken->organization;
                $this->audit->handle(
                    organization: $organization,
                    actor: null,
                    action: 'organization.channel_identity.verified',
                    targetType: OrganizationChannelIdentity::class,
                    targetId: (string) $identity->getKey(),
                    metadata: [
                        'channel' => 'telegram',
                        'verification_method' => 'telegram_crm_link',
                    ],
                );

                $linkToken->forceFill(['consumed_at' => now()])->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw new AuthorizationException('The Telegram identity is already linked to another staff member.');
        }
    }
}
