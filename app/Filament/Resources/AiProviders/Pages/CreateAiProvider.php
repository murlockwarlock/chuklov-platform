<?php

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Models\User;
use App\Modules\AI\Application\Actions\ConnectAiProvider;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAiProvider extends CreateRecord
{
    protected static string $resource = AiProviderResource::class;

    protected static ?string $title = 'Подключить сервис AI';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(ConnectAiProvider::class)->create($actor, $data);
    }
}
