<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\UpcomingBookingsWidget;
use Filament\Pages\Dashboard as BaseDashboard;

final class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Инфопанель';

    public function getColumns(): int
    {
        return 1;
    }

    public function getWidgets(): array
    {
        return [
            UpcomingBookingsWidget::class,
        ];
    }
}
