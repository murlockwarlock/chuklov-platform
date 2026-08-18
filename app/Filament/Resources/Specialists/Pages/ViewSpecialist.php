<?php

namespace App\Filament\Resources\Specialists\Pages;

use App\Filament\Resources\Specialists\SpecialistResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSpecialist extends ViewRecord
{
    protected static string $resource = SpecialistResource::class;

    protected static ?string $title = 'Специалист';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Редактировать специалиста')
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
        ];
    }
}
