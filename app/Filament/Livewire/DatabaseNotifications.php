<?php

namespace App\Filament\Livewire;

use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Notifications\Livewire\DatabaseNotifications as FilamentDatabaseNotifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final class DatabaseNotifications extends FilamentDatabaseNotifications
{
    public function getNotificationsQuery(): Builder|Relation
    {
        return parent::getNotificationsQuery()
            ->where('data->organization_id', app(OrganizationContext::class)->id());
    }
}
