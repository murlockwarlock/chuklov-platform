<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Modules\Services\Application\CreateService as CreateServiceAction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateServiceAction::class)->handle(
            name: $data['name'],
            summary: $data['summary'],
            isActive: $data['is_active'],
        );
    }
}
