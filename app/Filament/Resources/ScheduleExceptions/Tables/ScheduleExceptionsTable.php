<?php

namespace App\Filament\Resources\ScheduleExceptions\Tables;

use App\Models\User;
use App\Modules\Scheduling\Application\DeleteScheduleException;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ScheduleExceptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('specialist.display_name')->label('Specialist')->sortable(),
                TextColumn::make('exception_date')->label('Date')->date()->sortable(),
                TextColumn::make('exception_type')->label('Type'),
                TextColumn::make('start_time')->label('Start')->placeholder('All day'),
                TextColumn::make('end_time')->label('End')->placeholder('All day'),
                TextColumn::make('reason')->limit(80)->placeholder('—'),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label('Delete')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (ScheduleException $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(DeleteScheduleException::class)->handle($actor, $record);
                    }),
            ]);
    }
}
