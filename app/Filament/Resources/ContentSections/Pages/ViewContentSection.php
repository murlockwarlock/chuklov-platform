<?php

namespace App\Filament\Resources\ContentSections\Pages;

use App\Filament\Resources\ContentSections\ContentSectionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewContentSection extends ViewRecord
{
    protected static string $resource = ContentSectionResource::class;

    protected static ?string $title = 'Раздел контента';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Редактировать раздел')
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
        ];
    }
}
