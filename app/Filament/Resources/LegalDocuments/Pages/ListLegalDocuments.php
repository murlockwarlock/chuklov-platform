<?php

namespace App\Filament\Resources\LegalDocuments\Pages;

use App\Filament\Resources\LegalDocuments\LegalDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListLegalDocuments extends ListRecords
{
    protected static string $resource = LegalDocumentResource::class;

    protected static ?string $title = 'Документы и согласия';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Добавить документ'),
        ];
    }
}
