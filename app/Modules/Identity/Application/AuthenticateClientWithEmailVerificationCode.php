<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientEmailAuthChallenge;
use App\Modules\Identity\Domain\ValueObjects\NormalizedEmail;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthenticateClientWithEmailVerificationCode
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationFeatureGate $features,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $email, string $code): Client
    {
        $organization = $this->context->organization();
        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $normalizedEmail = NormalizedEmail::from($email)->value;
        $code = trim($code);

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            throw new InvalidEmailAuthenticationCode('The verification code is invalid.');
        }

        try {
            return $this->persist($organization, $normalizedEmail, $code);
        } catch (UniqueConstraintViolationException) {
            return $this->persist($organization, $normalizedEmail, $code);
        }
    }

    private function persist(Organization $organization, string $email, string $code): Client
    {
        $client = DB::transaction(function () use ($organization, $email, $code): ?Client {
            $challenge = ClientEmailAuthChallenge::query()
                ->where('organization_id', $organization->getKey())
                ->where('email', $email)
                ->lockForUpdate()
                ->first();
            $maxAttempts = max(1, (int) config('portal.email_auth.max_attempts', 5));

            if (! $challenge instanceof ClientEmailAuthChallenge
                || $challenge->consumed_at !== null
                || $challenge->expires_at->isPast()
                || $challenge->attempts >= $maxAttempts
                || ! Hash::check($code, $challenge->code_hash)) {
                if ($challenge instanceof ClientEmailAuthChallenge
                    && $challenge->consumed_at === null
                    && ! $challenge->expires_at->isPast()
                    && $challenge->attempts < $maxAttempts) {
                    $challenge->increment('attempts');
                }

                return null;
            }

            $challenge->forceFill(['consumed_at' => now()]);
            $challenge->save();

            $identity = ClientChannelIdentity::query()
                ->where('organization_id', $organization->getKey())
                ->where('channel', 'email')
                ->where('external_id', $email)
                ->lockForUpdate()
                ->first();

            if ($identity instanceof ClientChannelIdentity) {
                if ($identity->verification_status === ChannelIdentityStatus::Revoked) {
                    return null;
                }

                if ($identity->verification_status === ChannelIdentityStatus::Unverified) {
                    $identity->forceFill([
                        'verification_status' => ChannelIdentityStatus::Verified,
                        'verification_method' => 'email_verification_code',
                        'verified_at' => now(),
                    ]);
                    $identity->save();

                    $this->recordVerifiedIdentity($organization, $identity);
                }

                return $identity->client()->firstOrFail();
            }

            $client = new Client;
            $client->forceFill([
                'organization_id' => $organization->getKey(),
                'full_name' => null,
                'email' => $email,
                'language' => config('portal.default_locale', 'ru'),
                'timezone' => $organization->defaultTimezone(),
                'lead_source' => 'email_auth',
            ]);
            $client->save();

            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'client.created',
                targetType: Client::class,
                targetId: (string) $client->getKey(),
                metadata: ['source' => 'email_auth'],
            );

            $identity = new ClientChannelIdentity;
            $identity->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'channel' => 'email',
                'external_id' => $email,
                'verification_status' => ChannelIdentityStatus::Verified,
                'verification_method' => 'email_verification_code',
                'verified_at' => now(),
            ]);
            $identity->save();
            $this->recordVerifiedIdentity($organization, $identity);

            return $client->refresh();
        });

        if (! $client instanceof Client) {
            throw new InvalidEmailAuthenticationCode('The verification code is invalid.');
        }

        RateLimiter::clear($this->rateLimitKey($organization->getKey(), $email));

        return $client;
    }

    private function recordVerifiedIdentity(Organization $organization, ClientChannelIdentity $identity): void
    {
        $this->audit->handle(
            organization: $organization,
            actor: null,
            action: 'client.channel_identity.verified',
            targetType: ClientChannelIdentity::class,
            targetId: (string) $identity->getKey(),
            metadata: [
                'channel' => 'email',
                'verification_method' => 'email_verification_code',
            ],
        );
    }

    private function rateLimitKey(int $organizationId, string $email): string
    {
        return 'client-email-auth:request:'.$organizationId.':'.hash('sha256', $email);
    }
}
