<?php

namespace App\Filament\Resources\LegalDocuments\Pages;

use App\Filament\Resources\LegalDocuments\LegalDocumentResource;
use App\Filament\Support\LocalizedListRecords;
use Filament\Actions\CreateAction;

final class ListLegalDocuments extends LocalizedListRecords
{
    protected static string $resource = LegalDocumentResource::class;

    protected static ?string $title = 'Документы и согласия';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Добавить документ')),
        ];
    }
}
