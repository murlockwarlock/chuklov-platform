<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\ClientTelegramAuthenticationRequest;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use Illuminate\Support\Facades\DB;
use LogicException;

class InitiateTelegramWebAuthentication
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly OrganizationFeatureGate $features,
    ) {}

    public function handle(string $browserBinding): TelegramWebAuthenticationChallenge
    {
        $organization = $this->organizationContext->organization();
        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $botUsername = trim((string) config('portal.telegram.bot_username'));

        if ($botUsername === '' || preg_match('/^[A-Za-z0-9_]{5,32}$/', $botUsername) !== 1) {
            throw new LogicException('Telegram web authentication is not configured.');
        }

        $browserSessionHash = hash('sha256', $browserBinding);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ttl = max(1, (int) config('portal.telegram.web_auth_ttl', 600));

        $authenticationRequest = DB::transaction(function () use ($organization, $browserSessionHash, $token, $ttl): ClientTelegramAuthenticationRequest {
            ClientTelegramAuthenticationRequest::query()
                ->where('organization_id', $organization->getKey())
                ->where('browser_session_hash', $browserSessionHash)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $authenticationRequest = new ClientTelegramAuthenticationRequest;
            $authenticationRequest->forceFill([
                'organization_id' => $organization->getKey(),
                'token_hash' => hash('sha256', $token),
                'browser_session_hash' => $browserSessionHash,
                'expires_at' => now()->addSeconds($ttl),
            ]);
            $authenticationRequest->save();

            return $authenticationRequest;
        });

        return new TelegramWebAuthenticationChallenge(
            request: $authenticationRequest,
            url: 'https://t.me/'.$botUsername.'?start=web_'.$token,
        );
    }
}
