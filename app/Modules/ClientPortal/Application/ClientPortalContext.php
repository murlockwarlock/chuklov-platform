<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use LogicException;

class ClientPortalContext
{
    private ?Client $client = null;

    public function __construct(private readonly OrganizationContext $organizationContext) {}

    public function set(Client $client): void
    {
        if ($client->organization_id !== $this->organizationContext->id()) {
            throw new LogicException('The client does not belong to the current organization.');
        }

        $this->client = $client;
    }

    public function client(): Client
    {
        return $this->client ?? throw new LogicException('Client portal context is not resolved.');
    }

    public function id(): int
    {
        return $this->client()->getKey();
    }
}
