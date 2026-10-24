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

final readonly class UpdateClientNote
{
    private const MAX_BODY_LENGTH = 5000;

    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private OrganizationFeatureGate $features,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client, ClientNote $note, string $body, string $expectedBodyHash): ClientNote
    {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== (int) $organization->getKey()
            || (int) $note->organization_id !== (int) $organization->getKey()
            || (int) $note->client_id !== (int) $client->getKey()) {
            throw new AuthorizationException('The note is outside the current organization or client.');
        }

        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);

        $body = trim($body);

        if ($body === '' || mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw ValidationException::withMessages([
                'body' => 'Заметка должна содержать от 1 до '.self::MAX_BODY_LENGTH.' символов.',
            ]);
        }

        $updatedNote = DB::transaction(function () use ($actor, $client, $note, $organization, $body, $expectedBodyHash): ?ClientNote {
            $lockedClient = Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedNote = ClientNote::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $lockedClient->getKey())
                ->whereKey($note->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $currentBody = (string) $lockedNote->body;
            $currentBodyHash = hash('sha256', $currentBody);

            if (! hash_equals($currentBodyHash, $expectedBodyHash)) {
                if (hash_equals($currentBodyHash, hash('sha256', $body))) {
                    return $lockedNote;
                }

                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'client.note.update_conflicted',
                    targetType: ClientNote::class,
                    targetId: (string) $lockedNote->getKey(),
                    metadata: [
                        'client_id' => $lockedClient->getKey(),
                        'safe_error_code' => 'stale_note_version',
                    ],
                );

                return null;
            }

            $lockedNote->forceFill(['body' => $body])->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'client.note.updated',
                targetType: ClientNote::class,
                targetId: (string) $lockedNote->getKey(),
                metadata: [
                    'client_id' => $lockedClient->getKey(),
                    'body_present' => true,
                ],
            );

            return $lockedNote->refresh();
        });

        if (! $updatedNote instanceof ClientNote) {
            throw ValidationException::withMessages([
                'body' => 'Заметка уже изменилась. Закройте форму, снова откройте заметку и повторите правку.',
            ]);
        }

        return $updatedNote;
    }
}
