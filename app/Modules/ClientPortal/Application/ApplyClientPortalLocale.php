<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Identity\Application\UpdateClientProfileFromPortal;
use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Validation\ValidationException;

final class ApplyClientPortalLocale
{
    public function __construct(private readonly UpdateClientProfileFromPortal $updateProfile) {}

    public function handle(Client $client, string $locale): Client
    {
        $locale = strtolower(trim($locale));

        if (! in_array($locale, ['ru', 'en'], true)) {
            throw ValidationException::withMessages(['locale' => 'Выберите доступный язык.']);
        }

        return $this->updateProfile->handle($client, ['language' => $locale], ['language']);
    }
}
