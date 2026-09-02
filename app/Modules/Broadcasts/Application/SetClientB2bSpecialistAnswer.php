<?php

namespace App\Modules\Broadcasts\Application;

use App\Models\User;
use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\Broadcasts\Domain\Models\BroadcastClientProfile;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetClientB2bSpecialistAnswer
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User|Client $actor,
        Client $client,
        B2bSpecialistAnswer $answer,
        string $source,
    ): BroadcastClientProfile {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        if ($actor instanceof User) {
            $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        } elseif ((int) $actor->getKey() !== (int) $client->getKey()) {
            throw new AuthorizationException('A client may only update its own B2B profile answer.');
        }

        if (! in_array($source, ['portal', 'telegram', 'crm'], true)) {
            throw ValidationException::withMessages(['source' => 'The B2B profile source is invalid.']);
        }

        return DB::transaction(function () use ($actor, $client, $answer, $source, $organization): BroadcastClientProfile {
            Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $profile = BroadcastClientProfile::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->lockForUpdate()
                ->first();
            $oldAnswer = $profile?->getRawOriginal('b2b_specialist_answer');

            $profile ??= new BroadcastClientProfile;
            $profile->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'b2b_specialist_answer' => $answer->value,
            ]);
            $profile->save();

            if ($oldAnswer !== $answer->value) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor instanceof User ? $actor : null,
                    action: 'b2b.client.specialist_answer.updated',
                    targetType: Client::class,
                    targetId: (string) $client->getKey(),
                    metadata: [
                        'source' => $source,
                        'old_answer' => $oldAnswer,
                        'answer' => $answer->value,
                    ],
                );
            }

            return $profile->refresh();
        });
    }
}
