<?php

namespace App\Filament\Resources\ScheduleExceptions\Tables;

use App\Models\User;
use App\Modules\Scheduling\Application\DeleteScheduleException;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
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
                    ->schema([
                        Checkbox::make('acknowledge_impact')
                            ->label('Acknowledge impact on future bookings')
                            ->default(false),
                        TextInput::make('impact_digest')
                            ->label('Current impact preview digest')
                            ->maxLength(64),
                    ])
                    ->action(function (ScheduleException $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(DeleteScheduleException::class)->handle(
                            actor: $actor,
                            exception: $record,
                            acknowledgeImpact: (bool) ($data['acknowledge_impact'] ?? false),
                            acknowledgedImpactDigest: isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
                        );
                    }),
            ]);
    }
}
