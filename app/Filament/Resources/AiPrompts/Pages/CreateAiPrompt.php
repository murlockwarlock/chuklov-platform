<?php

namespace App\Filament\Resources\AiPrompts\Pages;

use App\Filament\Resources\AiPrompts\AiPromptResource;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Resources\Pages\CreateRecord;

class CreateAiPrompt extends CreateRecord
{
    protected static string $resource = AiPromptResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organization_id'] = app(OrganizationContext::class)->id();

        return $data;
    }
}
