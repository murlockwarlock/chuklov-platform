<?php

namespace App\Filament\Resources\Specialists\Pages;

use App\Filament\Resources\Specialists\SpecialistResource;
use App\Filament\Support\LocalizedViewRecord;
use App\Filament\Support\SpecialistTelegramLinkAction;
use Filament\Actions\EditAction;

class ViewSpecialist extends LocalizedViewRecord
{
    protected static string $resource = SpecialistResource::class;

    protected static ?string $title = 'Специалист';

    protected function getHeaderActions(): array
    {
        return [
            SpecialistTelegramLinkAction::make(),
            EditAction::make()
                ->label(__('Редактировать специалиста'))
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),
        ];
    }
}
