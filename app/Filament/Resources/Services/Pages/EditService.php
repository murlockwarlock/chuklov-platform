<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Modules\Services\Application\UpdateService;
use App\Modules\Services\Domain\Models\Service;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Service, 404);

        return app(UpdateService::class)->handle(
            service: $record,
            name: $data['name'],
            summary: $data['summary'],
            isActive: $data['is_active'],
        );
    }
}
