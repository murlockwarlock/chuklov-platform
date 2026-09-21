<?php

namespace App\Filament\Resources\ContentSections\Pages;

use App\Filament\Resources\ContentSections\ContentSectionResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;

class ListContentSections extends LocalizedListRecords
{
    protected static string $resource = ContentSectionResource::class;

    protected static ?string $title = 'Разделы контента';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
