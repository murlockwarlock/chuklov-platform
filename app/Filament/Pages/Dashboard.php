<?php

namespace App\Filament\Pages;

use App\Filament\Support\TimezoneOptions;
use App\Filament\Widgets\AnalyticsAcquisitionWidget;
use App\Filament\Widgets\AnalyticsAiFailuresWidget;
use App\Filament\Widgets\AnalyticsFinanceWidget;
use App\Filament\Widgets\AnalyticsIngestionFailuresWidget;
use App\Filament\Widgets\AnalyticsKpiWidget;
use App\Filament\Widgets\AnalyticsSchedulingWidget;
use App\Filament\Widgets\UpcomingBookingsWidget;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Organizations\Application\OrganizationContext;
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

    protected static ?string $title = 'Аналитика';

    public function getColumns(): int
    {
        return 1;
    }

    public function getSubheading(): ?string
    {
        try {
            $organization = app(OrganizationContext::class)->organization();
            $period = DashboardPeriod::fromFilters($this->filters, $organization->defaultTimezone());
            $startDate = CarbonImmutable::createFromFormat('!Y-m-d', $period->startDate, 'UTC')->format('d.m.Y');
            $endDate = CarbonImmutable::createFromFormat('!Y-m-d', $period->endDate, 'UTC')->format('d.m.Y');
            $range = $startDate === $endDate ? $startDate : $startDate.' — '.$endDate;

            return 'Период: '.$range.' · Часовой пояс: '.TimezoneOptions::label($period->timezone).' ('.$period->timezone.')';
        } catch (\Throwable) {
            return 'Период: последние 30 дней';
        }
    }

    public function getWidgets(): array
    {
        return [
            AnalyticsKpiWidget::class,
            AnalyticsAcquisitionWidget::class,
            AnalyticsSchedulingWidget::class,
            AnalyticsFinanceWidget::class,
            UpcomingBookingsWidget::class,
            AnalyticsAiFailuresWidget::class,
            AnalyticsIngestionFailuresWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            FilterAction::make()
                ->label('Период')
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
