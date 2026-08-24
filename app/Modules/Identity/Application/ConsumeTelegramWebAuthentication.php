<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientAcquisitionRegistration;
use App\Modules\Identity\Domain\Models\ClientTelegramAuthenticationRequest;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Support\Facades\DB;

class ConsumeTelegramWebAuthentication
{
    public function __construct(private readonly OrganizationContext $organizationContext) {}

    public function handle(int $requestId, string $browserBinding): ?Client
    {
        $organization = $this->organizationContext->organization();

        return DB::transaction(function () use ($requestId, $browserBinding, $organization): ?Client {
            $authenticationRequest = ClientTelegramAuthenticationRequest::query()
                ->whereKey($requestId)
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->first();

            if (! $authenticationRequest instanceof ClientTelegramAuthenticationRequest
                || ! hash_equals($authenticationRequest->browser_session_hash, hash('sha256', $browserBinding))
                || $authenticationRequest->expires_at->isPast()) {
                throw new InvalidTelegramWebAuthentication('The Telegram authentication request is invalid or expired.');
            }

            if ($authenticationRequest->consumed_at !== null
                && ($authenticationRequest->client_id === null
                    || ! ClientAcquisitionRegistration::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('telegram_authentication_request_id', $authenticationRequest->getKey())
                        ->where('client_id', $authenticationRequest->client_id)
                        ->whereNull('finalized_at')
                        ->exists())) {
                throw new InvalidTelegramWebAuthentication('The Telegram authentication request is invalid or expired.');
            }

            if ($authenticationRequest->verified_at === null
                || $authenticationRequest->client_id === null
                || $authenticationRequest->client_channel_identity_id === null) {
                return null;
            }

            $client = Client::query()
                ->whereKey($authenticationRequest->client_id)
                ->where('organization_id', $organization->getKey())
                ->whereHas('channelIdentities', static fn ($query) => $query
                    ->whereKey($authenticationRequest->client_channel_identity_id)
                    ->where('channel', 'telegram')
                    ->where('verification_status', ChannelIdentityStatus::Verified))
                ->first();

            if (! $client instanceof Client) {
                throw new InvalidTelegramWebAuthentication('The verified Telegram identity is no longer active.');
            }

            $authenticationRequest->forceFill(['consumed_at' => now()]);
            $authenticationRequest->save();

            return $client;
        });
    }
}
