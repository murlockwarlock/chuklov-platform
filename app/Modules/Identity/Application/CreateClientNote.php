<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientNote;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateClientNote
{
    private const MAX_BODY_LENGTH = 5000;

    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private OrganizationFeatureGate $features,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client, string $body): ClientNote
    {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);

        $body = trim($body);

        if ($body === '' || mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw ValidationException::withMessages([
                'body' => 'Заметка должна содержать от 1 до '.self::MAX_BODY_LENGTH.' символов.',
            ]);
        }

        return DB::transaction(function () use ($actor, $client, $organization, $body): ClientNote {
            $lockedClient = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $note = new ClientNote;
            $note->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $lockedClient->getKey(),
                'author_user_id' => $actor->getKey(),
                'body' => $body,
                'created_at' => now(),
            ]);
            $note->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'client.note.created',
                targetType: ClientNote::class,
                targetId: (string) $note->getKey(),
                metadata: [
                    'client_id' => $lockedClient->getKey(),
                    'body_present' => true,
                ],
            );

            return $note->load('author');
        });
    }
}
