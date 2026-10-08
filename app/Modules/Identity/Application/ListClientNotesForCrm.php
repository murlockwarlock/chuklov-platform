<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientNote;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListClientNotesForCrm
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
    ) {}

    /**
     * @param  Builder<ClientNote>  $query
     * @return Builder<ClientNote>
     */
    public function apply(User $actor, Client $client, Builder $query): Builder
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);

        abort_unless((int) $client->organization_id === (int) $organization->getKey(), 403);

        return $query
            ->where('client_notes.organization_id', $organization->getKey())
            ->where('client_notes.client_id', $client->getKey())
            ->with('author:id,name')
            ->orderByDesc('client_notes.created_at')
            ->orderByDesc('client_notes.id');
    }
}
