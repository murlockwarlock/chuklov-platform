<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Support\Facades\DB;

final class RefreshTelegramClientIdentity
{
    public function handle(Organization $organization, VerifiedChannelIdentity $verifiedIdentity): ?Client
    {
        if ($verifiedIdentity->channel !== 'telegram') {
            return null;
        }

        return DB::transaction(function () use ($organization, $verifiedIdentity): ?Client {
            $identity = ClientChannelIdentity::query()
                ->where('organization_id', $organization->getKey())
                ->where('channel', 'telegram')
                ->where('external_id', $verifiedIdentity->externalId)
                ->where('verification_status', ChannelIdentityStatus::Verified)
                ->lockForUpdate()
                ->first();

            if (! $identity instanceof ClientChannelIdentity) {
                return null;
            }

            if ($verifiedIdentity->username !== null
                && $identity->external_username !== $verifiedIdentity->username) {
                $identity->forceFill(['external_username' => $verifiedIdentity->username])->save();
            }

            return $identity->client()->first();
        });
    }
}
