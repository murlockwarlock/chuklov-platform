<?php

namespace App\Filament\Resources\ScenarioRules\Tables;

use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
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
                TextColumn::make('rule_key')->label('Rule')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('trigger_event')->label('Trigger')->badge(),
                TextColumn::make('delay_value')
                    ->label('Delay')
                    ->state(fn (ScenarioRule $record): string => $record->delay_value.' '.self::unit($record->delay_unit)),
                TextColumn::make('templateVersion.version')->label('Template version')->formatStateUsing(
                    fn (mixed $state): string => 'v'.(string) $state,
                ),
                TextColumn::make('version')->label('Rule version')->sortable(),
                IconColumn::make('is_enabled')->label('Enabled')->boolean()->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    private static function unit(ScenarioDelayUnit $unit): string
    {
        return match ($unit) {
            ScenarioDelayUnit::Minutes => 'min',
            ScenarioDelayUnit::Hours => 'h',
            ScenarioDelayUnit::Days => 'd',
        };
    }
}
