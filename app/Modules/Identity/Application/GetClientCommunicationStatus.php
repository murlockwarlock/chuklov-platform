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

final readonly class GetClientCommunicationStatus
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
    ) {}

    /** @return list<string> */
    public function handle(User $actor, Client $client): array
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);

        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $statuses = ClientChannelIdentity::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->select(['id', 'organization_id', 'client_id', 'channel', 'verification_status', 'verified_at'])
            ->orderBy('channel')
            ->orderBy('id')
            ->limit(10)
            ->get()
            ->map(static function (ClientChannelIdentity $identity): string {
                $channel = match ($identity->channel) {
                    'telegram' => 'Telegram',
                    'email' => 'Email',
                    default => 'Другой канал',
                };
                $status = match ($identity->verification_status) {
                    ChannelIdentityStatus::Verified => 'подтверждён',
                    ChannelIdentityStatus::Revoked => 'отключён',
                    default => 'не подтверждён',
                };

                return $channel.' — '.$status;
            })
            ->all();

        return array_values($statuses);
    }
}
