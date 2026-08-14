<?php

namespace App\Filament\Resources\ScenarioRules\Tables;

use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ScenarioRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Правило')->searchable()->sortable(),
                TextColumn::make('trigger_event')
                    ->label('Когда')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::eventLabel($state)),
                TextColumn::make('delay_value')
                    ->label('Через')
                    ->state(fn (ScenarioRule $record): string => $record->delay_value.' '.self::unit($record->delay_unit)),
                TextColumn::make('templateVersion.template.name')->label('Сообщение')->placeholder('—'),
                IconColumn::make('is_enabled')->label('Активно')->boolean()->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    private static function unit(ScenarioDelayUnit $unit): string
    {
        return match ($unit) {
            ScenarioDelayUnit::Minutes => 'мин',
            ScenarioDelayUnit::Hours => 'ч',
            ScenarioDelayUnit::Days => 'дн.',
        };
    }

    private static function eventLabel(mixed $event): string
    {
        $value = $event instanceof BackedEnum ? $event->value : (string) $event;

        return $value === 'booking.completed' ? 'После визита' : 'Событие';
    }
}
