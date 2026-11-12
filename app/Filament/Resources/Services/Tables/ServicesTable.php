<?php

namespace App\Filament\Resources\Services\Tables;

use App\Filament\Support\FormValidationFeedback;
use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Services\Application\SetServiceActive;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('name')
                    ->label(__('Название'))
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->width('14rem'),
                TextColumn::make('summary')
                    ->label(__('Описание'))
                    ->limit(60)
                    ->wrap()
                    ->visibleFrom('xl')
                    ->width('18rem'),
                TextColumn::make('catalog_type')
                    ->label(__('Тип'))
                    ->formatStateUsing(fn (CatalogItemType|string|null $state): string => match ($state instanceof CatalogItemType ? $state : CatalogItemType::tryFrom((string) $state)) {
                        CatalogItemType::PhysicalProduct => __('Физический товар'),
                        CatalogItemType::OnlineProduct => __('Онлайн-товар'),
                        CatalogItemType::GiftCertificate => __('Подарочный сертификат'),
                        default => __('Услуга'),
                    })
                    ->visibleFrom('xl')
                    ->width('8rem'),
                TextColumn::make('duration_minutes')
                    ->label(__('Длительность'))
                    ->suffix(' мин.')
                    ->sortable()
                    ->visibleFrom('xl')
                    ->width('8rem'),
                TextColumn::make('price_minor')
                    ->label(__('Цена'))
                    ->visibleFrom('xl')
                    ->width('8rem')
                    ->state(function (Service $record): string {
                        if ($record->price_minor === null || $record->price_currency === null) {
                            return '—';
                        }

                        try {
                            return Money::ofMinor($record->price_minor, $record->price_currency)->toDecimalString()
                                .' '.$record->price_currency;
                        } catch (InvalidArgumentException) {
                            return '—';
                        }
                    }),
                TextColumn::make('is_active')
                    ->label(__('Статус'))
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Активна') : __('В архиве'))
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable()
                    ->width('8rem'),
            ])
            ->emptyStateHeading(__('Услуг пока нет'))
            ->emptyStateDescription(__('Добавьте консультации или процедуры, доступные для записи клиентов.'))
            ->recordActions([
                EditAction::make()->label(__('Редактировать')),
                Action::make('archive')
                    ->label(__('Архивировать'))
                    ->color('danger')
                    ->icon('heroicon-o-archive-box')
                    ->requiresConfirmation()
                    ->modalHeading(__('Архивировать услугу?'))
                    ->modalDescription(__('Услуга исчезнет из новых записей и клиентского каталога. История записей и оплат останется без изменений.'))
                    ->schema([
                        ...ScheduleImpactPreview::components(),
                    ])
                    ->fillForm(fn (Service $record): array => ScheduleImpactPreview::stateFromImpact(
                        app(ScheduleMutationImpactCalculator::class)->forServiceChange($record, ['is_active' => false]),
                    ))
                    ->visible(fn (Service $record): bool => $record->is_active)
                    ->action(function (Action $action, Service $record, array $data, Schema $schema): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        try {
                            app(SetServiceActive::class)->handle(
                                actor: $actor,
                                service: $record,
                                isActive: false,
                                acknowledgeImpact: (bool) ($data['acknowledge_impact'] ?? false),
                                acknowledgedImpactDigest: isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
                            );
                            Notification::make()->success()->title(__('Услуга архивирована'))->send();
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
                    }),
                Action::make('restore')
                    ->label(__('Вернуть из архива'))
                    ->color('success')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->modalHeading(__('Вернуть услугу из архива?'))
                    ->modalDescription(__('Услуга снова станет доступна для новых записей и клиентского каталога.'))
                    ->schema([
                        ...ScheduleImpactPreview::components(),
                    ])
                    ->fillForm(fn (Service $record): array => ScheduleImpactPreview::stateFromImpact(
                        app(ScheduleMutationImpactCalculator::class)->forServiceChange($record, ['is_active' => true]),
                    ))
                    ->visible(fn (Service $record): bool => ! $record->is_active)
                    ->action(function (Action $action, Service $record, array $data, Schema $schema): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        try {
                            app(SetServiceActive::class)->handle(
                                actor: $actor,
                                service: $record,
                                isActive: true,
                                acknowledgeImpact: (bool) ($data['acknowledge_impact'] ?? false),
                                acknowledgedImpactDigest: isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
                            );
                            Notification::make()->success()->title(__('Услуга возвращена из архива'))->send();
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
                    }),
            ], RecordActionsPosition::AfterColumns)
            ->extraAttributes(['class' => 'services-table']);
    }
}
