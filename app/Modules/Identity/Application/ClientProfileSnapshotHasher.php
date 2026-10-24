<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\Client;

final class ClientProfileSnapshotHasher
{
    public function forClient(Client $client): string
    {
        return hash('sha256', json_encode([
            'id' => (int) $client->getKey(),
            'organization_id' => (int) $client->organization_id,
            'full_name' => $client->getRawOriginal('full_name'),
            'demographics' => array_map($client->getRawOriginal(...), NormalizeClientDemographics::FIELDS),
            'name_confirmed_at' => $client->getRawOriginal('name_confirmed_at'),
            'email' => $client->getRawOriginal('email'),
            'phone' => $client->getRawOriginal('phone'),
            'language' => $client->getRawOriginal('language'),
            'timezone' => $client->getRawOriginal('timezone'),
            'timezone_source' => $client->getRawOriginal('timezone_source') ?? 'organization',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
