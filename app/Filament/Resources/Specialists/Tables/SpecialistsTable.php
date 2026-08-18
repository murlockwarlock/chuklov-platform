<?php

namespace App\Filament\Resources\Specialists\Tables;

use App\Filament\Support\ScheduleImpactPreview;
use App\Filament\Support\TimezoneOptions;
use App\Models\User;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Specialists\Application\SetSpecialistActive;
use App\Modules\Specialists\Application\UpdateSpecialist;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class SpecialistsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('display_name')->label('Имя специалиста')->searchable()->sortable()->wrap(),
                IconColumn::make('is_active')->label('Доступен')->boolean()->sortable(),
                TextColumn::make('timezone')
                    ->label('Часовой пояс')
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? 'Часовой пояс организации'
                        : TimezoneOptions::label($state))
                    ->placeholder('Часовой пояс организации')
                    ->visibleFrom('sm'),
                TextColumn::make('staffUser.name')->label('Сотрудник CRM')->placeholder('Не привязан')->visibleFrom('md'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Доступен'),
            ])
            ->recordActions([
                ViewAction::make()->label('Открыть'),
                EditAction::make()->label('Редактировать'),
                ActionGroup::make([
                    Action::make('activate')
                        ->label('Сделать доступным')
                        ->color('success')
                        ->icon('heroicon-o-check-circle')
                        ->requiresConfirmation()
                        ->visible(fn (Specialist $record): bool => ! $record->is_active)
                        ->action(function (Specialist $record): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User, 403);

                            app(SetSpecialistActive::class)->handle($actor, $record, true);
                        }),
                    Action::make('deactivate')
                        ->label('Скрыть из записи')
                        ->color('danger')
                        ->icon('heroicon-o-eye-slash')
                        ->requiresConfirmation()
                        ->schema([
                            ...ScheduleImpactPreview::components(),
                        ])
                        ->fillForm(fn (Specialist $record): array => ScheduleImpactPreview::stateFromImpact(
                            app(ScheduleMutationImpactCalculator::class)->forSpecialistChange(
                                specialist: $record,
                                newIsActive: false,
                                newTimezone: $record->timezone,
                            ),
                        ))
                        ->visible(fn (Specialist $record): bool => $record->is_active)
                        ->action(function (Specialist $record, array $data, Schema $schema): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User, 403);

                            try {
                                app(SetSpecialistActive::class)->handle(
                                    $actor,
                                    $record,
                                    false,
                                    (bool) ($data['acknowledge_impact'] ?? false),
                                    isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
                                );
                            } catch (ValidationException $exception) {
                                ScheduleImpactPreview::applyValidationPreview($schema, $exception);

                                throw $exception;
                            }
                        }),
                    Action::make('linkStaffUser')
                        ->label('Привязать сотрудника CRM')
                        ->icon('heroicon-o-link')
                        ->schema([
                            Select::make('staff_user_id')
                                ->required()
                                ->searchable()
                                ->options(fn (): array => SpecialistTableOptions::staffUsers()),
                        ])
                        ->visible(fn (Specialist $record): bool => $record->staff_user_id === null)
                        ->action(function (Specialist $record, array $data): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User, 403);

                            app(UpdateSpecialist::class)->handle(
                                actor: $actor,
                                specialist: $record,
                                displayName: $record->display_name,
                                isActive: $record->is_active,
                                timezone: $record->timezone,
                                staffUserId: (int) $data['staff_user_id'],
                            );
                        }),
                    Action::make('unlinkStaffUser')
                        ->label('Отвязать сотрудника CRM')
                        ->icon('heroicon-o-x-mark')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (Specialist $record): bool => $record->staff_user_id !== null)
                        ->action(function (Specialist $record): void {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User, 403);

                            app(UpdateSpecialist::class)->handle(
                                actor: $actor,
                                specialist: $record,
                                displayName: $record->display_name,
                                isActive: $record->is_active,
                                timezone: $record->timezone,
                                staffUserId: null,
                            );
                        }),
                ])
                    ->label('Действия')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->button()
                    ->color('gray')
                    ->size('sm'),
            ]);
    }
}
