<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\UpcomingBookingsWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;

final class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Инфопанель';

    public function getWidgets(): array
    {
        return [
            AccountWidget::class,
            UpcomingBookingsWidget::class,
        ];
    }
}
