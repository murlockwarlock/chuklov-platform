<?php

namespace App\Filament\Resources\B2bLeads\Pages;

use App\Filament\Resources\B2bLeads\B2bLeadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListB2bLeads extends ListRecords
{
    protected static string $resource = B2bLeadResource::class;

    protected static ?string $title = 'B2B-лиды';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Новый B2B-лид'),
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
