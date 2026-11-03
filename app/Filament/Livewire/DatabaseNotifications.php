<?php

namespace App\Filament\Livewire;

use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Enums\DatabaseNotificationsPosition;
use Filament\Livewire\DatabaseNotifications as FilamentDatabaseNotifications;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final class DatabaseNotifications extends FilamentDatabaseNotifications
{
    public function getTrigger(): ?View
    {
        $triggerView = (($this->position ?? filament()->getDatabaseNotificationsPosition()) === DatabaseNotificationsPosition::Topbar)
            ? 'filament.notifications.topbar-database-notifications-trigger'
            : 'filament.notifications.sidebar-database-notifications-trigger';

        return view($triggerView);
    }

    public function getNotificationsQuery(): Builder|Relation
    {
        return parent::getNotificationsQuery()
            ->where('data->organization_id', app(OrganizationContext::class)->id());
    }
}
