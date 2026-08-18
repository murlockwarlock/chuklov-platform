<?php

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Models\User;
use App\Modules\AI\Application\Actions\UpdateAiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAiProvider extends EditRecord
{
    protected static string $resource = AiProviderResource::class;

    protected static ?string $title = 'Редактировать AI-провайдера';

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof AiProviderConfiguration, 404);

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(UpdateAiProviderConfiguration::class)->handle($actor, $record, $data);
    }
}
