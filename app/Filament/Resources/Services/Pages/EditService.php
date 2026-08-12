<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Models\User;
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
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(UpdateService::class)->handle(
            actor: $actor,
            service: $record,
            name: $data,
        );
    }
}
