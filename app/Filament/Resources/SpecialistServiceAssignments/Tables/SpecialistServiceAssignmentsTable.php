<?php

namespace App\Filament\Resources\SpecialistServiceAssignments\Tables;

use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Scheduling\Application\RemoveSpecialistServiceAssignment;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

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
                        ...ScheduleImpactPreview::components(),
                    ])
                    ->fillForm(fn (SpecialistServiceAssignment $record): array => ScheduleImpactPreview::stateFromImpact(
                        app(ScheduleMutationImpactCalculator::class)->forAssignmentRemoval(
                            specialistId: (int) $record->specialist_id,
                            serviceId: (int) $record->service_id,
                        ),
                    ))
                    ->action(function (SpecialistServiceAssignment $record, array $data, Schema $schema): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        try {
                            app(RemoveSpecialistServiceAssignment::class)->handle(
                                $actor,
                                $record,
                                (bool) ($data['acknowledge_impact'] ?? false),
                                isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
                            );
                        } catch (ValidationException $exception) {
                            ScheduleImpactPreview::applyValidationPreview($schema, $exception);

                            throw $exception;
                        }
                    }),
            ]);
    }
}
