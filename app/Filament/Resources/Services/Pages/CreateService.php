<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Models\User;
use App\Modules\Services\Application\CreateService as CreateServiceAction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(CreateServiceAction::class)->handle(
            actor: $actor,
            name: $data['name'],
            summary: $data['summary'],
            isActive: $data['is_active'],
        );
    }
}
