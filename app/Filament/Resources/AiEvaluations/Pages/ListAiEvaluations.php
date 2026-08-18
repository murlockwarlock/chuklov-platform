<?php

namespace App\Filament\Resources\AiEvaluations\Pages;

use App\Filament\Resources\AiEvaluations\AiEvaluationResource;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiEvaluations extends ListRecords
{
    protected static string $resource = AiEvaluationResource::class;

    protected static ?string $title = 'Наборы тестов AI';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['organization_id'] = app(OrganizationContext::class)->id();

                    return $data;
                }),
        ];
    }
}
