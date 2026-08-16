<?php

namespace App\Filament\Resources\AiEvaluations\Pages;

use App\Filament\Resources\AiEvaluations\AiEvaluationResource;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Resources\Pages\CreateRecord;

class CreateAiEvaluation extends CreateRecord
{
    protected static string $resource = AiEvaluationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organization_id'] = app(OrganizationContext::class)->id();

        return $data;
    }
}
