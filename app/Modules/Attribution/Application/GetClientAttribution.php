<?php

namespace App\Modules\Attribution\Application;

use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;

final class GetClientAttribution
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(Client $client): ?ClientAttribution
    {
        return ClientAttribution::query()
            ->where('organization_id', $this->context->id())
            ->where('client_id', $client->getKey())
            ->first();
    }
}
