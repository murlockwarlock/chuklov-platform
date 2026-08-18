<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class GetClientCommunicationIdentities
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
    ) {}

    /**
     * @return list<array{
     *     channel: string,
     *     externalId: string,
     *     verificationStatus: ChannelIdentityStatus,
     *     verifiedAt: ?\DateTimeInterface,
     *     summary: string,
     * }>
     */
    public function handle(User $actor, Client $client): array
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);

        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        return ClientChannelIdentity::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->select(['id', 'organization_id', 'client_id', 'channel', 'external_id', 'verification_status', 'verified_at'])
            ->orderBy('channel')
            ->orderBy('id')
            ->limit(10)
            ->get()
            ->map(static function (ClientChannelIdentity $identity): array {
                $channelName = match ($identity->channel) {
                    'telegram' => 'Telegram',
                    'email' => 'Email',
                    default => 'Другой канал',
                };

                $statusLabel = match ($identity->verification_status) {
                    ChannelIdentityStatus::Verified => 'подтверждён',
                    ChannelIdentityStatus::Revoked => 'отключён',
                    default => 'не подтверждён',
                };

                $idLabel = match ($identity->channel) {
                    'telegram' => 'Telegram ID: '.$identity->external_id,
                    default => $identity->external_id,
                };

                return [
                    'channel' => $identity->channel,
                    'externalId' => (string) $identity->external_id,
                    'verificationStatus' => $identity->verification_status,
                    'verifiedAt' => $identity->verified_at,
                    'summary' => $channelName.' ('.$idLabel.', '.$statusLabel.')',
                ];
            })
            ->all();
    }
}
