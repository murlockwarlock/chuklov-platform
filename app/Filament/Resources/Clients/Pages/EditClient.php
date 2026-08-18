<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\User;
use App\Modules\Identity\Application\UpdateClientProfileFromCrm;
use App\Modules\Identity\Domain\Models\Client;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected static ?string $title = 'Редактировать клиента';

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Client, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(UpdateClientProfileFromCrm::class)->handle($actor, $record, $data);
    }
}
