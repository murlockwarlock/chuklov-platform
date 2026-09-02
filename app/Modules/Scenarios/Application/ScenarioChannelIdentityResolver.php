<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioChannelIdentity;
use App\Modules\Specialists\Domain\Models\Specialist;

final class ScenarioChannelIdentityResolver
{
    public function resolve(ScenarioAction $action, string $channel): ?ScenarioChannelIdentity
    {
        if ($action->recipient_type === 'client' && $action->client_id !== null) {
            $identity = ClientChannelIdentity::query()
                ->where('organization_id', $action->organization_id)
                ->where('client_id', $action->client_id)
                ->where('channel', $channel)
                ->where('verification_status', ChannelIdentityStatus::Verified->value)
                ->first();
        } elseif ($action->recipient_type === 'internal' && $action->recipient_user_id !== null) {
            $isActiveMember = OrganizationMembership::query()
                ->where('organization_id', $action->organization_id)
                ->where('user_id', $action->recipient_user_id)
                ->active()
                ->exists();

            if (! $isActiveMember) {
                return null;
            }

            if (Specialist::query()
                ->where('organization_id', $action->organization_id)
                ->where('staff_user_id', $action->recipient_user_id)
                ->where('notifications_enabled', false)
                ->exists()) {
                return null;
            }

            $identity = OrganizationChannelIdentity::query()
                ->where('organization_id', $action->organization_id)
                ->where('user_id', $action->recipient_user_id)
                ->where('channel', $channel)
                ->where('verification_status', ChannelIdentityStatus::Verified->value)
                ->first();
        } else {
            return null;
        }

        return $identity === null
            ? null
            : new ScenarioChannelIdentity($channel, (string) $identity->external_id);
    }
}
