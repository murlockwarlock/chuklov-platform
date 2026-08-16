<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Pages;

use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewMedicalSession extends ViewRecord
{
    protected static string $resource = MedicalSessionResource::class;

    protected static ?string $title = 'Сеанс';

    protected static ?string $breadcrumb = 'Сеанс';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label('Редактировать')
                ->url(fn (): string => MedicalSessionResource::getUrl('edit', [
                    'client' => $this->getParentRecord(),
                    'record' => $this->getRecord(),
                ]))
                ->visible(fn (): bool => MedicalSessionResource::canEdit($this->getRecord())),
        ];
    }
}
