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
            ? 'filament-panels::components.topbar.database-notifications-trigger'
            : 'filament-panels::components.sidebar.database-notifications-trigger';

        return view('filament.notifications.database-notifications-trigger', compact('triggerView'));
    }

    public function getNotificationsQuery(): Builder|Relation
    {
        return parent::getNotificationsQuery()
            ->where('data->organization_id', app(OrganizationContext::class)->id());
    }
}
