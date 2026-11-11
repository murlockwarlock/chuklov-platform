<?php

namespace App\Filament\Resources\Specialists\Tables;

use App\Filament\Resources\Specialists\SpecialistResource;
use App\Filament\Support\CrmEntityLinks;
use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Specialists\Application\SetSpecialistActive;
use App\Modules\Specialists\Application\SpecialistImageUrlResolver;
use App\Modules\Specialists\Application\UpdateSpecialist;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class SpecialistsTable
{
    public static function configure(Table $table): Table
    {
        $canViewSpecialists = SpecialistResource::canViewAny();

        return $table
            ->stackedOnMobile()
            ->columns([
                ImageColumn::make('profile_image_path')
                    ->label(__('Фото'))
                    ->getStateUsing(fn (Specialist $record): ?string => app(SpecialistImageUrlResolver::class)->resolve($record))
                    ->defaultImageUrl(fn (Specialist $record): string => self::avatar($record))
                    ->alt(fn (Specialist $record): string => $record->display_name)
                    ->imageSize(48)
                    ->circular(),
                TextColumn::make('display_name')
                    ->label(__('Имя и фамилия'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->url(fn (Specialist $record): ?string => CrmEntityLinks::specialistUrl($record, $canViewSpecialists))
                    ->color(fn (Specialist $record): ?string => CrmEntityLinks::specialistUrl($record, $canViewSpecialists) === null ? null : 'primary')
                    ->disabledClick(fn (Specialist $record): bool => CrmEntityLinks::specialistUrl($record, $canViewSpecialists) === null),
                TextColumn::make('specialization')
                    ->label(__('Специализация'))
                    ->placeholder(__('Не указана'))
                    ->wrap()
                    ->visibleFrom('sm'),
                TextColumn::make('city')
                    ->label(__('Город'))
                    ->placeholder(__('Не указан'))
                    ->visibleFrom('md'),
                TextColumn::make('is_active')
                    ->label(__('Статус'))
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Доступен') : __('Скрыт из записи'))
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->visibleFrom('sm'),
                TextColumn::make('staffUser.name')
                    ->label(__('Аккаунт CRM'))
                    ->placeholder(__('Не привязан'))
                    ->visibleFrom('lg'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('Доступен')),
            ])
            ->emptyStateHeading(__('Специалистов пока нет'))
            ->emptyStateDescription(__('Добавьте врачей или консультантов клиники, чтобы настроить их рабочие часы и услуги.'))
            ->recordActions([
                ViewAction::make()->label(__('Открыть')),
                EditAction::make()->label(__('Редактировать')),
                ActionGroup::make([
                    Action::make('activate')
                        ->label(__('Сделать доступным'))
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
                        ->label(__('Скрыть из записи'))
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
                        ->label(__('Привязать сотрудника CRM'))
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
                        ->label(__('Отвязать сотрудника CRM'))
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
                    ->label(__('Действия'))
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->button()
                    ->color('gray')
                    ->size('sm'),
            ]);
    }

    private static function avatar(Specialist $record): string
    {
        $parts = preg_split('/\s+/u', trim($record->display_name)) ?: [];
        $initials = collect(array_slice($parts, 0, 2))
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
        $initials = $initials === '' ? '?' : $initials;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><rect width="96" height="96" rx="48" fill="#f1e2cd"/><text x="48" y="57" fill="#9c6831" font-family="Arial,sans-serif" font-size="28" font-weight="700" text-anchor="middle">'.htmlspecialchars($initials, ENT_QUOTES | ENT_XML1).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
