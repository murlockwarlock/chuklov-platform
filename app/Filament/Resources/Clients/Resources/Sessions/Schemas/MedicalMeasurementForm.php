<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Schemas;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Sessions\Application\MedicalMeasurementCalculator;
use App\Modules\Sessions\Application\MedicalVitalsSettings;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Placeholder;

final class MedicalMeasurementForm
{
    public static function section(): Section
    {
        return Section::make(__('0. Показатели до и после сеанса'))
            ->schema([
                Toggle::make('measurements.after_enabled')
                    ->label(__('Ввести показатели после сеанса'))
                    ->helperText(__('Включите после завершения процедуры. Все поля остаются необязательными.'))
                    ->default(false)
                    ->live(),
                Section::make(__('До сеанса'))
                    ->schema(self::phaseFields('before'))
                    ->columns(2),
                Section::make(__('После сеанса'))
                    ->schema(self::phaseFields('after'))
                    ->columns(2)
                    ->visible(fn (Get $get): bool => (bool) $get('measurements.after_enabled')),
                Placeholder::make('measurement_input_hint')
                    ->hiddenLabel()
                    ->content(__('PD и MAP рассчитываются автоматически только при наличии SYS и DIA. Оценка цвета зависит от настроек CRM.'))
                    ->columnSpanFull(),
            ])
            ->collapsible()
            ->columnSpanFull();
    }

    /** @return array<int, TextInput|Placeholder> */
    private static function phaseFields(string $phase): array
    {
        $prefix = 'measurements.'.$phase.'.vitals.';

        return [
            self::input($prefix.'sys', __('SYS'), 'мм рт. ст.', 'numeric', $phase, 'sys'),
            self::input($prefix.'dia', __('DIA'), 'мм рт. ст.', 'numeric', $phase, 'dia'),
            self::input($prefix.'pulse', __('Пульс'), 'уд/мин', 'numeric', $phase, 'pulse'),
            self::input($prefix.'pi', __('Индекс перфузии'), '%', 'decimal', $phase, 'pi'),
            self::input($prefix.'spo2', __('Сатурация'), '%', 'numeric', $phase, 'spo2'),
            Placeholder::make($phase.'_derived')
                ->label(__('Расчётные показатели'))
                ->content(fn (Get $get): string => self::derivedSummary($get, $phase))
                ->columnSpanFull(),
        ];
    }

    private static function input(
        string $name,
        string $label,
        string $suffix,
        string $mode,
        string $phase,
        string $key,
    ): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->suffix($suffix)
            ->numeric()
            ->inputMode($mode)
            ->nullable()
            ->extraFieldWrapperAttributes(fn (Get $get): array => self::stateAttributes($get, $phase, $key))
            ->live(onBlur: true);
    }

    private static function stateAttributes(Get $get, string $phase, string $key): array
    {
        $value = $get('measurements.'.$phase.'.vitals.'.$key);
        if (! is_numeric($value)) {
            return [];
        }

        $thresholds = app(MedicalVitalsSettings::class)->forOrganization(app(OrganizationContext::class)->id());
        $state = app(MedicalMeasurementCalculator::class)->evaluate(
            [$phase => ['vitals' => [$key => $value]]],
            $thresholds,
        )[$phase][$key]['state'] ?? 'unknown';

        return match ($state) {
            'red' => ['class' => 'rounded-lg border border-danger-300 bg-danger-50 p-1'],
            'yellow' => ['class' => 'rounded-lg border border-warning-300 bg-warning-50 p-1'],
            default => [],
        };
    }

    private static function derivedSummary(Get $get, string $phase): string
    {
        $sys = $get('measurements.'.$phase.'.vitals.sys');
        $dia = $get('measurements.'.$phase.'.vitals.dia');
        if (! is_numeric($sys) || ! is_numeric($dia)) {
            return __('PD и MAP появятся после ввода SYS и DIA.');
        }

        $pd = (float) $sys - (float) $dia;
        $map = (int) round((float) $dia + $pd / 3);
        $vitals = [
            'sys' => (int) $sys,
            'dia' => (int) $dia,
            'pulse' => $get('measurements.'.$phase.'.vitals.pulse'),
            'pi' => $get('measurements.'.$phase.'.vitals.pi'),
            'spo2' => $get('measurements.'.$phase.'.vitals.spo2'),
            'pd' => $pd,
            'map' => $map,
        ];
        $thresholds = app(MedicalVitalsSettings::class)->forOrganization(app(OrganizationContext::class)->id());
        $evaluation = app(MedicalMeasurementCalculator::class)->evaluate(
            [$phase => ['vitals' => $vitals]],
            $thresholds,
        )[$phase] ?? [];
        $status = collect($evaluation)
            ->filter(fn (array $item): bool => ($item['value'] ?? null) !== null && ($item['state'] ?? 'unknown') !== 'unknown')
            ->map(fn (array $item, string $key): string => self::stateLabel($key, (string) $item['state']))
            ->values()
            ->implode(', ');

        return __('PD: :pd мм рт. ст. · MAP: :map мм рт. ст.', ['pd' => self::number($pd), 'map' => $map])
            .($status !== '' ? ' · '.$status : '');
    }

    private static function stateLabel(string $key, string $state): string
    {
        $label = match ($key) {
            'sys' => 'SYS',
            'dia' => 'DIA',
            'pulse' => __('Пульс'),
            'pi' => __('ИП'),
            'spo2' => __('SpO2'),
            default => $key,
        };
        $stateLabel = match ($state) {
            'red' => __('красная отметка'),
            'yellow' => __('внимание'),
            default => __('в пределах настроек'),
        };

        return $label.': '.$stateLabel;
    }

    private static function number(float $value): string
    {
        return number_format($value, 0, ',', ' ');
    }
}
