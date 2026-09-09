<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\OrganizationChannelLinkToken;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class InitiateTelegramOrganizationLink
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(User $actor, int $userId): string
    {
        $organization = $this->organizationContext->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewScenarios);

        if ($actor->getKey() !== $userId) {
            $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $userId)
            ->active()
            ->first();
        if ($membership === null) {
            throw new AuthorizationException('The selected staff member is not active in this organization.');
        }

        $botUsername = trim((string) config('portal.telegram.bot_username'));
        if ($botUsername === '' || preg_match('/^[A-Za-z0-9_]{5,32}$/', $botUsername) !== 1) {
            throw new LogicException('Telegram connection is not configured.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ttl = max(1, (int) config('portal.telegram.link_ttl', 600));

        DB::transaction(function () use ($organization, $userId, $token, $ttl): void {
            OrganizationChannelLinkToken::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $userId)
                ->where('channel', 'telegram')
                ->where('flow', 'crm.telegram.connect')
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $linkToken = new OrganizationChannelLinkToken;
            $linkToken->forceFill([
                'organization_id' => $organization->getKey(),
                'user_id' => $userId,
                'channel' => 'telegram',
                'flow' => 'crm.telegram.connect',
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addSeconds($ttl),
            ])->save();
        });

        return 'https://t.me/'.$botUsername.'?start='.rawurlencode('staff_'.$token);
    }
}
