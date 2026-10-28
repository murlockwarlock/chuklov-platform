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
                    ->required()
                    ->validationMessages(['required' => __('Выберите тип изменения расписания.')]),
                Select::make('specialist_id')
                    ->label(__('Специалист'))
                    ->options(fn (): array => Specialist::query()
                        ->where('organization_id', app(OrganizationContext::class)->id())
                        ->orderBy('display_name')
                        ->pluck('display_name', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->validationMessages(['required' => __('Выберите специалиста.')]),
                DatePicker::make('exception_date')
                    ->label(__('Дата'))
                    ->required(fn (Get $get): bool => $get('change_type') !== 'unavailable')
                    ->validationMessages(['required' => __('Укажите дату изменения расписания.')])
                    ->visible(fn (Get $get): bool => $get('change_type') !== 'unavailable'),
                Select::make('exception_type')
                    ->label(__('Тип изменения'))
                    ->options([
                        ScheduleExceptionType::DayOff->value => __('Выходной день'),
                        ScheduleExceptionType::CustomWindow->value => __('Дополнительные часы'),
                    ])
                    ->required(fn (Get $get): bool => $get('change_type') !== 'unavailable')
                    ->validationMessages(['required' => __('Выберите тип изменения.')])
                    ->live(),
                TimePicker::make('start_time')
                    ->label(__('Начало'))
                    ->seconds(false)
                    ->required(fn (Get $get): bool => $get('change_type') !== 'unavailable' && $get('exception_type') === ScheduleExceptionType::CustomWindow->value)
                    ->validationMessages(['required' => __('Укажите время начала.')])
                    ->visible(fn (Get $get): bool => $get('change_type') !== 'unavailable' && $get('exception_type') === ScheduleExceptionType::CustomWindow->value),
                TimePicker::make('end_time')
                    ->label(__('Окончание'))
                    ->seconds(false)
                    ->required(fn (Get $get): bool => $get('change_type') !== 'unavailable' && $get('exception_type') === ScheduleExceptionType::CustomWindow->value)
                    ->rules(['after:start_time'])
                    ->validationMessages([
                        'required' => __('Укажите время окончания.'),
                        'after' => __('Окончание должно быть позже начала.'),
                    ])
                    ->visible(fn (Get $get): bool => $get('change_type') !== 'unavailable' && $get('exception_type') === ScheduleExceptionType::CustomWindow->value),
                DateTimePicker::make('starts_at')
                    ->label(__('Начало'))
                    ->seconds(false)
                    ->timezone(fn (): string => app(OrganizationContext::class)->organization()->defaultTimezone())
                    ->required(fn (Get $get): bool => $get('change_type') === 'unavailable')
                    ->validationMessages(['required' => __('Укажите дату и время начала недоступности.')])
                    ->visible(fn (Get $get): bool => $get('change_type') === 'unavailable'),
                DateTimePicker::make('ends_at')
                    ->label(__('Окончание'))
                    ->seconds(false)
                    ->timezone(fn (): string => app(OrganizationContext::class)->organization()->defaultTimezone())
                    ->required(fn (Get $get): bool => $get('change_type') === 'unavailable')
                    ->validationMessages(['required' => __('Укажите дату и время окончания недоступности.')])
                    ->rules(['after:starts_at'])
                    ->visible(fn (Get $get): bool => $get('change_type') === 'unavailable'),
                TextInput::make('reason')
                    ->label(__('Причина'))
                    ->maxLength(500)
                    ->nullable(),
                ...ScheduleImpactPreview::components(),
            ]);
    }
}
