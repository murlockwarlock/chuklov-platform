<?php

namespace App\Modules\Specialists\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SyncSpecialistTelegramIdentity
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        Organization $organization,
        Specialist $specialist,
        ?string $telegramId,
    ): void {
        if ($telegramId !== null) {
            throw ValidationException::withMessages([
                'telegram_id' => 'Telegram подключается только через подтверждённую ссылку из CRM.',
            ]);
        }

        if ((int) $specialist->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSpecialists);

        DB::transaction(function () use ($actor, $organization, $specialist, $telegramId): void {
            $staffUserId = $specialist->staff_user_id;

            if ($staffUserId === null) {
                return;
            }

            if (! OrganizationMembership::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $staffUserId)
                ->active()
                ->exists()) {
                throw new AuthorizationException('The staff user is not an active member of this organization.');
            }

            $identity = OrganizationChannelIdentity::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $staffUserId)
                ->where('channel', 'telegram')
                ->lockForUpdate()
                ->first();

            if ($telegramId === null) {
                if ($identity === null) {
                    return;
                }

                $identity->delete();
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'specialist.telegram_identity.removed',
                    targetType: Specialist::class,
                    targetId: (string) $specialist->getKey(),
                    metadata: ['channel' => 'telegram'],
                );

                return;
            }

        });
    }
}
