<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AnalyticsAcquisitionWidget;
use App\Filament\Widgets\AnalyticsAiFailuresWidget;
use App\Filament\Widgets\AnalyticsFinanceWidget;
use App\Filament\Widgets\AnalyticsIngestionFailuresWidget;
use App\Filament\Widgets\AnalyticsSchedulingWidget;
use App\Filament\Widgets\UpcomingBookingsWidget;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Actions\FilterAction;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersAction;
use Filament\Schemas\Components\Utilities\Get;

final class Dashboard extends BaseDashboard
{
    use HasFiltersAction;

    protected static ?string $title = 'Инфопанель';

    public function getColumns(): int
    {
        return 1;
    }

    public function getWidgets(): array
    {
        return [
            UpcomingBookingsWidget::class,
            AnalyticsAcquisitionWidget::class,
            AnalyticsSchedulingWidget::class,
            AnalyticsFinanceWidget::class,
            AnalyticsAiFailuresWidget::class,
            AnalyticsIngestionFailuresWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            FilterAction::make()
                ->modalHeading('Период отчёта')
                ->schema([
                    Select::make('period')
                        ->label('Период')
                        ->options(DashboardPeriod::options())
                        ->default(DashboardPeriod::DefaultPreset)
                        ->required()
                        ->live(),
                    DatePicker::make('start_date')
                        ->label('Начало')
                        ->format('Y-m-d')
                        ->displayFormat('d.m.Y')
                        ->requiredIf('period', DashboardPeriod::Custom)
                        ->rule('date_format:Y-m-d', fn (Get $get): bool => $get('period') === DashboardPeriod::Custom)
                        ->beforeOrEqual('end_date')
                        ->visible(fn (Get $get): bool => $get('period') === DashboardPeriod::Custom),
                    DatePicker::make('end_date')
                        ->label('Конец')
                        ->format('Y-m-d')
                        ->displayFormat('d.m.Y')
                        ->requiredIf('period', DashboardPeriod::Custom)
                        ->rule('date_format:Y-m-d', fn (Get $get): bool => $get('period') === DashboardPeriod::Custom)
                        ->afterOrEqual('start_date')
                        ->rule(
                            fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $startValue = $get('start_date');

                                if (! is_string($startValue) || ! is_string($value)) {
                                    return;
                                }

                                try {
                                    $start = CarbonImmutable::createFromFormat('!Y-m-d', $startValue, 'UTC');
                                    $end = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
                                } catch (\Throwable) {
                                    return;
                                }

                                if ($start instanceof CarbonImmutable && $end instanceof CarbonImmutable
                                    && $start->lessThanOrEqualTo($end)
                                    && $start->diffInDays($end) + 1 > 366) {
                                    $fail('Выберите период не более одного года.');
                                }
                            },
                            fn (Get $get): bool => $get('period') === DashboardPeriod::Custom,
                        )
                        ->visible(fn (Get $get): bool => $get('period') === DashboardPeriod::Custom),
                ]),
        ];
    }
}
