<?php

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Resources\Pages\CreateRecord;

class CreateAiProvider extends CreateRecord
{
    protected static string $resource = AiProviderResource::class;

    protected static ?string $title = 'Подключить AI-провайдера';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organization_id'] = app(OrganizationContext::class)->id();

        return $data;
    }
}
