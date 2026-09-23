<?php

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Support\LocalizedEditRecord;
use App\Models\User;
use App\Modules\AI\Application\Actions\ConnectAiProvider;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use Illuminate\Database\Eloquent\Model;

class EditAiProvider extends LocalizedEditRecord
{
    protected static string $resource = AiProviderResource::class;

    protected static ?string $title = 'Настроить сервис AI';

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof AiProviderConfiguration, 404);

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $updatedProvider = app(ConnectAiProvider::class)->update($actor, $record, $data);

        $this->record = $updatedProvider;

        return $updatedProvider;
    }

    protected function afterSave(): void
    {
        if (is_array($this->data)) {
            unset($this->data['api_key']);
        }
    }
}
