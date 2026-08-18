<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;

    protected static ?string $title = 'Клиенты';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
