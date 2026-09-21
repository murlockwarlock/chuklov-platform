<?php

namespace App\Filament\Resources\KnowledgeSources\Pages;

use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;

final class ListKnowledgeSources extends LocalizedListRecords
{
    protected static string $resource = KnowledgeSourceResource::class;

    protected static ?string $title = 'База знаний';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
