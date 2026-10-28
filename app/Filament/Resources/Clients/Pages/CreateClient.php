<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\LocalizedCreateRecord;
use App\Models\User;
use App\Modules\Identity\Application\CreateClient as CreateClientAction;
use App\Modules\Identity\Application\NormalizeClientDemographics;
use Illuminate\Database\Eloquent\Model;

class CreateClient extends LocalizedCreateRecord
{
    protected static string $resource = ClientResource::class;

    protected static ?string $title = 'Добавить клиента';

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(CreateClientAction::class)->handle(
            actor: $actor,
            fullName: implode(' ', array_filter([$data['last_name'] ?? null, $data['first_name'] ?? null, $data['middle_name'] ?? null])),
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            language: $data['language'],
            timezone: $data['timezone'],
            leadSource: $data['lead_source'] ?? null,
            referralCode: $data['referral_code'] ?? null,
            demographics: array_intersect_key($data, array_flip(NormalizeClientDemographics::FIELDS)),
        );
    }
}
