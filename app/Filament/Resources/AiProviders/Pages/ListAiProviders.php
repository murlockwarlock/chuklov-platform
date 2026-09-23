<?php

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;

class ListAiProviders extends LocalizedListRecords
{
    protected static string $resource = AiProviderResource::class;

    protected static ?string $title = 'Провайдеры и модели';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
