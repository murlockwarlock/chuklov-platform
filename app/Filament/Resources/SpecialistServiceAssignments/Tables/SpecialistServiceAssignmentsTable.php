<?php

namespace App\Filament\Resources\SpecialistServiceAssignments\Tables;

use App\Models\User;
use App\Modules\Scheduling\Application\RemoveSpecialistServiceAssignment;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SpecialistServiceAssignmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('specialist.display_name')->label('Specialist')->sortable(),
                TextColumn::make('service.name')->label('Service')->sortable(),
                TextColumn::make('created_at')->label('Assigned')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label('Remove')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Checkbox::make('acknowledge_impact')
                            ->label('Acknowledge impact on future bookings')
                            ->default(false),
                    ])
                    ->action(function (SpecialistServiceAssignment $record, array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        app(RemoveSpecialistServiceAssignment::class)->handle(
                            $actor,
                            $record,
                            (bool) ($data['acknowledge_impact'] ?? false),
                        );
                    }),
            ]);
    }
}
