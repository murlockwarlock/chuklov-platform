<?php

namespace App\Filament\Livewire;

use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Livewire\DatabaseNotifications as FilamentDatabaseNotifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\DatabaseNotification;

final class DatabaseNotifications extends FilamentDatabaseNotifications
{
    /** @return Builder<DatabaseNotification>|Relation<DatabaseNotification, Model, mixed> */
    public function getNotificationsQuery(): Builder|Relation
    {
        return parent::getNotificationsQuery()
            ->where('data->organization_id', app(OrganizationContext::class)->id());
    }
}
