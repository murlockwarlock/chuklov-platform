<?php

namespace App\Filament\Resources\ContentSections\Pages;

use App\Filament\Resources\ContentSections\ContentSectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContentSections extends ListRecords
{
    protected static string $resource = ContentSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
