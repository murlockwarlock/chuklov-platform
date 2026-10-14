<?php

namespace App\Filament\Resources\ScheduleExceptions\Tables;

use App\Filament\Resources\Specialists\SpecialistResource;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Scheduling\Application\DeleteScheduleException;
use App\Modules\Scheduling\Application\DeleteUnavailablePeriod;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use App\Modules\Organizations\Application\OrganizationContext;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class ScheduleExceptionsTable
{
    public static function configure(Table $table): Table
    {
        $canViewSpecialists = SpecialistResource::canViewAny();

        return $table
            ->columns([
                TextColumn::make('specialist.display_name')
                    ->label(__('Специалист'))
                    ->sortable()
                    ->url(fn (ScheduleException $record): ?string => CrmEntityLinks::specialistUrl($record->specialist, $canViewSpecialists))
                    ->color(fn (ScheduleException $record): ?string => CrmEntityLinks::specialistUrl($record->specialist, $canViewSpecialists) === null ? null : 'primary')
                    ->disabledClick(fn (ScheduleException $record): bool => CrmEntityLinks::specialistUrl($record->specialist, $canViewSpecialists) === null),
                TextColumn::make('change_kind')
                    ->label(__('Тип'))
                    ->formatStateUsing(fn (?string $state): string => $state === 'unavailable' ? __('Недоступное время') : __('Изменение рабочего времени')),
                TextColumn::make('starts_at')
                    ->label(__('Начало'))
                    ->state(fn (ScheduleException $record): string => self::startLabel($record))
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label(__('Окончание'))
                    ->state(fn (ScheduleException $record): string => self::endLabel($record))
                    ->placeholder(__('Весь день'))
                    ->sortable(),
                TextColumn::make('reason')->label(__('Причина'))->limit(80)->placeholder('—'),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label(__('Удалить'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        ...ScheduleImpactPreview::components(),
                    ])
                    ->fillForm(function (ScheduleException $record): array {
                        if (($record->getAttribute('change_kind') ?? 'exception') === 'unavailable') {
                            return [];
                        }

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
                        if (($record->getAttribute('change_kind') ?? 'exception') === 'unavailable') {
                            $period = UnavailablePeriod::query()
                                ->where('organization_id', app(OrganizationContext::class)->id())
                                ->whereKey((int) $record->getAttribute('source_id'))
                                ->firstOrFail();
                            app(DeleteUnavailablePeriod::class)->handle($actor, $period);

                            return;
                        }
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

    private static function startLabel(ScheduleException $record): string
    {
        if (($record->getAttribute('change_kind') ?? 'exception') === 'unavailable') {
            return self::dateTimeLabel($record->getAttribute('starts_at'));
        }

        $date = $record->getAttribute('exception_date');
        if ($date instanceof \DateTimeInterface) {
            return $date->format('d.m.Y').' '.((string) $record->getAttribute('start_time') ?: '00:00');
        }

        return trim((string) $date);
    }

    private static function endLabel(ScheduleException $record): string
    {
        if (($record->getAttribute('change_kind') ?? 'exception') === 'unavailable') {
            return self::dateTimeLabel($record->getAttribute('ends_at'));
        }

        return (string) ($record->getAttribute('end_time') ?? '');
    }

    private static function dateTimeLabel(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return CarbonImmutable::parse((string) $value)
            ->setTimezone(app(OrganizationContext::class)->defaultTimezone())
            ->format('d.m.Y H:i');
    }
}
