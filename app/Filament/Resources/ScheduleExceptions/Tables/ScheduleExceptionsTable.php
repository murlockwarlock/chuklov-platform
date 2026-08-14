<?php

namespace App\Filament\Resources\ScheduleExceptions\Tables;

use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Scheduling\Application\DeleteScheduleException;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class ScheduleExceptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('specialist.display_name')->label('Специалист')->sortable(),
                TextColumn::make('exception_date')->label('Дата')->date()->sortable(),
                TextColumn::make('exception_type')
                    ->label('Тип')
                    ->formatStateUsing(fn (ScheduleExceptionType|string $state): string => match ($state instanceof ScheduleExceptionType ? $state : ScheduleExceptionType::tryFrom($state)) {
                        ScheduleExceptionType::DayOff => 'Выходной день',
                        ScheduleExceptionType::CustomWindow => 'Дополнительные часы',
                        default => 'Не указан',
                    }),
                TextColumn::make('start_time')->label('Начало')->placeholder('Весь день'),
                TextColumn::make('end_time')->label('Окончание')->placeholder('Весь день'),
                TextColumn::make('reason')->label('Причина')->limit(80)->placeholder('—'),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label('Удалить')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        ...ScheduleImpactPreview::components(),
                    ])
                    ->fillForm(function (ScheduleException $record): array {
                        $record->loadMissing('specialist');

                        return ScheduleImpactPreview::stateFromImpact(
                            app(ScheduleMutationImpactCalculator::class)->forExceptionDeletion(
                                specialist: $record->specialist,
                                exception: $record,
                            ),
                        );
                    })
                    ->action(function (ScheduleException $record, array $data, Schema $schema): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        try {
                            app(DeleteScheduleException::class)->handle(
                                actor: $actor,
                                exception: $record,
                                acknowledgeImpact: (bool) ($data['acknowledge_impact'] ?? false),
                                acknowledgedImpactDigest: isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
                            );
                        } catch (ValidationException $exception) {
                            ScheduleImpactPreview::applyValidationPreview($schema, $exception);

                            throw $exception;
                        }
                    }),
            ]);
    }
}
