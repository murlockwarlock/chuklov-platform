<?php

namespace App\Filament\Resources\ScenarioActions\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ScenarioActionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Action')->sortable(),
                TextColumn::make('rule.name')->label('Rule')->searchable(),
                TextColumn::make('event.event_name')->label('Source event')->badge(),
                TextColumn::make('client.full_name')->label('Client')->placeholder('Internal recipient')->searchable(),
                TextColumn::make('recipientUser.name')->label('Staff recipient')->placeholder('—'),
                TextColumn::make('scheduled_for')->label('Scheduled')->dateTime()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('terminal_reason')->label('Outcome reason')->placeholder('—'),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
