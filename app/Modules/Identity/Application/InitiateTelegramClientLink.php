<?php

namespace App\Modules\Identity\Application;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Identity\Domain\Models\ClientChannelLinkToken;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use Illuminate\Support\Facades\DB;
use LogicException;

class InitiateTelegramClientLink
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly ClientPortalContext $clientContext,
        private readonly OrganizationFeatureGate $features,
    ) {}

    public function handle(): string
    {
        $organization = $this->organizationContext->organization();
        $client = $this->clientContext->client();
        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $botUsername = trim((string) config('portal.telegram.bot_username'));

        if ($botUsername === '' || preg_match('/^[A-Za-z0-9_]{5,32}$/', $botUsername) !== 1) {
            throw new LogicException('Telegram connection is not configured.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ttl = max(1, (int) config('portal.telegram.link_ttl', 600));

        DB::transaction(function () use ($organization, $client, $token, $ttl): void {
            ClientChannelLinkToken::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('channel', 'telegram')
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $linkToken = new ClientChannelLinkToken;
            $linkToken->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'channel' => 'telegram',
                'flow' => 'portal.telegram.connect',
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addSeconds($ttl),
            ]);
            $linkToken->save();
        });

        return 'https://t.me/'.$botUsername.'?start='.rawurlencode($token);
    }
}
