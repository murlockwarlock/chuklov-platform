<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\User;
use App\Modules\Identity\Application\CreateClient as CreateClientAction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(CreateClientAction::class)->handle(
            actor: $actor,
            fullName: $data['full_name'],
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            language: $data['language'],
            timezone: $data['timezone'],
            leadSource: $data['lead_source'] ?? null,
            referralCode: $data['referral_code'] ?? null,
        );
    }
}
