<?php

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Models\User;
use App\Modules\AI\Application\Actions\UpdateAiProviderConfiguration;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAiProvider extends EditRecord
{
    protected static string $resource = AiProviderResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(UpdateAiProviderConfiguration::class)->handle($actor, $record, $data);
    }
}
