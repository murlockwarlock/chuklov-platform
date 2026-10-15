<?php

namespace App\Filament\Resources\ScheduleExceptions\Schemas;

use App\Filament\Support\ScheduleImpactPreview;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ScheduleExceptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('change_type')
                    ->label(__('Тип изменения'))
                    ->options([
                        'working_intervals' => __('Рабочие интервалы на отдельные даты'),
                        'unavailable' => __('Недоступное время'),
                    ])
                    ->default(fn (): string => request()->query('change_type') === 'unavailable' ? 'unavailable' : 'working_intervals')
                    ->live()
                    ->required(),
                Select::make('specialist_id')
                    ->label(__('Специалист'))
                    ->options(fn (): array => Specialist::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->orderBy('display_name')
                        ->pluck('display_name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                DatePicker::make('exception_date')
                    ->label(__('Дата'))
                    ->required(fn (Get $get): bool => $get('change_type') !== 'unavailable')
                    ->visible(fn (Get $get): bool => $get('change_type') !== 'unavailable'),
                Select::make('exception_type')
                    ->label(__('Тип изменения'))
                    ->options([
                        ScheduleExceptionType::DayOff->value => __('Выходной день'),
                        ScheduleExceptionType::CustomWindow->value => __('Дополнительные часы'),
                    ])
                    ->required(fn (Get $get): bool => $get('change_type') !== 'unavailable')
                    ->live(),
                TimePicker::make('start_time')
                    ->label(__('Начало'))
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => $get('change_type') !== 'unavailable' && $get('exception_type') === ScheduleExceptionType::CustomWindow->value),
                TimePicker::make('end_time')
                    ->label(__('Окончание'))
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => $get('change_type') !== 'unavailable' && $get('exception_type') === ScheduleExceptionType::CustomWindow->value),
                DateTimePicker::make('starts_at')
                    ->label(__('Начало'))
                    ->seconds(false)
                    ->timezone(fn (): string => app(OrganizationContext::class)->organization()->defaultTimezone())
                    ->required(fn (Get $get): bool => $get('change_type') === 'unavailable')
                    ->visible(fn (Get $get): bool => $get('change_type') === 'unavailable'),
                DateTimePicker::make('ends_at')
                    ->label(__('Окончание'))
                    ->seconds(false)
                    ->timezone(fn (): string => app(OrganizationContext::class)->organization()->defaultTimezone())
                    ->required(fn (Get $get): bool => $get('change_type') === 'unavailable')
                    ->visible(fn (Get $get): bool => $get('change_type') === 'unavailable'),
                TextInput::make('reason')
                    ->label(__('Причина'))
                    ->maxLength(500),
                ...ScheduleImpactPreview::components(),
            ]);
    }
}
