<?php

namespace App\Filament\Resources\UnavailablePeriods\Tables;

use App\Filament\Resources\Specialists\SpecialistResource;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\FormValidationFeedback;
use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\DeleteUnavailablePeriod;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Throwable;

class UnavailablePeriodsTable
{
    public static function configure(Table $table): Table
    {
        $canViewSpecialists = SpecialistResource::canViewAny();

        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('specialist.display_name')
                    ->label(__('Специалист'))
                    ->sortable()
                    ->url(fn (UnavailablePeriod $record): ?string => CrmEntityLinks::specialistUrl($record->specialist, $canViewSpecialists))
                    ->color(fn (UnavailablePeriod $record): ?string => CrmEntityLinks::specialistUrl($record->specialist, $canViewSpecialists) === null ? null : 'primary')
                    ->disabledClick(fn (UnavailablePeriod $record): bool => CrmEntityLinks::specialistUrl($record->specialist, $canViewSpecialists) === null),
                TextColumn::make('starts_at')
                    ->label(__('Начало'))
                    ->dateTime('d.m.Y H:i')
                    ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label(__('Окончание'))
                    ->dateTime('d.m.Y H:i')
                    ->timezone(fn (): string => app(OrganizationContext::class)->defaultTimezone())
                    ->sortable(),
                TextColumn::make('reason')->label(__('Причина'))->limit(80)->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make()->label(__('Редактировать')),
                Action::make('delete')
                    ->label(__('Удалить'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        ...ScheduleImpactPreview::components(),
                    ])
                    ->fillForm(fn (UnavailablePeriod $record): array => ScheduleImpactPreview::stateFromImpact(
                        app(ScheduleMutationImpactCalculator::class)->forUnavailablePeriod(
                            specialist: $record->specialist,
                            startsAt: $record->starts_at,
                            endsAt: $record->ends_at,
                        ),
                    ))
                    ->action(function (Action $action, UnavailablePeriod $record, array $data, Schema $schema): void {
                        try {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User, 403);
                            app(DeleteUnavailablePeriod::class)->handle(
                                actor: $actor,
                                period: $record,
                                acknowledgeImpact: (bool) ($data['acknowledge_impact'] ?? false),
                                acknowledgedImpactDigest: isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
                            );
                        } catch (ValidationException $exception) {
                            ScheduleImpactPreview::applyValidationPreview($schema, $exception);
                            $mapped = FormValidationFeedback::forAction($exception, $action);
                            FormValidationFeedback::notifyValidation($mapped, __('Нужно подтвердить изменение'));

                            throw $mapped;
                        } catch (Throwable $exception) {
                            $mapped = FormValidationFeedback::unexpectedForAction($exception, $action);
                            FormValidationFeedback::notifyValidation($mapped);
                            throw $mapped;
                        }

                        Notification::make()->success()->title(__('Период недоступности удалён'))->send();
                    }),
            ]);
    }
}
