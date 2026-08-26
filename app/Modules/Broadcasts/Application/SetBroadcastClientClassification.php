<?php

namespace App\Modules\Broadcasts\Application;

use App\Models\User;
use App\Modules\Broadcasts\Domain\Models\BroadcastClientProfile;
use App\Modules\Broadcasts\Domain\Models\BroadcastClientTag;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SetBroadcastClientClassification
{
    public function __construct(private OrganizationContext $context, private OrganizationAuthorizer $authorizer, private RecordAuditEvent $audit) {}

    /** @param list<mixed> $tags */
    public function handle(User $actor, Client $client, ?string $b2bRole, array $tags): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        if ((int) $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
        $role = $b2bRole === null ? null : trim($b2bRole);
        if ($role === '') {
            $role = null;
        }
        if ($role !== null && mb_strlen($role) > 80) {
            throw ValidationException::withMessages(['b2b_role' => 'B2B-роль должна быть не длиннее 80 символов.']);
        }
        $normalizedTags = [];
        foreach ($tags as $tag) {
            if (! is_string($tag) || trim($tag) === '' || mb_strlen(trim($tag)) > 80) {
                throw ValidationException::withMessages(['broadcast_tags' => 'Метка должна быть не длиннее 80 символов.']);
            }
            $normalizedTags[] = mb_strtolower(trim($tag));
        }
        $normalizedTags = array_values(array_unique($normalizedTags));
        if (count($normalizedTags) > 50) {
            throw ValidationException::withMessages(['broadcast_tags' => 'Для одного клиента можно сохранить не более 50 меток.']);
        }

        DB::transaction(function () use ($actor, $client, $organization, $role, $normalizedTags): void {
            BroadcastClientProfile::query()->updateOrCreate(['organization_id' => $organization->getKey(), 'client_id' => $client->getKey()], ['b2b_role' => $role]);
            BroadcastClientTag::query()->where('organization_id', $organization->getKey())->where('client_id', $client->getKey())->whereNotIn('tag', $normalizedTags)->delete();
            foreach ($normalizedTags as $tag) {
                BroadcastClientTag::query()->firstOrCreate(['organization_id' => $organization->getKey(), 'client_id' => $client->getKey(), 'tag' => $tag]);
            }
            $this->audit->handle($organization, $actor, 'broadcast.client.classification.updated', Client::class, (string) $client->getKey(), ['tag_count' => count($normalizedTags), 'b2b_role_set' => $role !== null]);
        });
    }
}
