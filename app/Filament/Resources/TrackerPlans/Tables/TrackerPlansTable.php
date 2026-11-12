<?php

namespace App\Filament\Resources\TrackerPlans\Tables;

use App\Filament\Support\FormValidationFeedback;
use App\Models\User;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Tracker\Application\SetTrackerPlanActive;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Throwable;

final class TrackerPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('name')->label(__('Название'))->searchable()->sortable(),
                TextColumn::make('currentVersion.version')->label(__('Версия'))->formatStateUsing(fn (mixed $state): string => $state === null ? '—' : 'v'.$state),
                TextColumn::make('currentVersion.price_minor')->label(__('Цена'))->state(function (TrackerPlan $record): string {
                    $version = $record->currentVersion;

                    return $version instanceof TrackerPlanVersion ? Money::ofMinor($version->price_minor, $version->currencyCode())->toDecimalString().' '.$version->currencyCode()->value : '—';
                }),
                TextColumn::make('currentVersion.duration_days')->label(__('Доступ'))->suffix(' дн.'),
                IconColumn::make('is_active')->label(__('Доступен для покупки'))->boolean(),
                IconColumn::make('is_visible')->label(__('Виден клиентам'))->boolean(),
            ])
            ->recordActions([
                EditAction::make()->label(__('Редактировать')),
                Action::make('archive')
                    ->label(__('Архивировать'))
                    ->color('warning')
                    ->icon('heroicon-o-archive-box')
                    ->requiresConfirmation()
                    ->modalHeading(__('Архивировать тариф?'))
                    ->modalDescription(__('Тариф перестанет использоваться для новых покупок. Уже выданный доступ и история оплат останутся без изменений.'))
                    ->visible(fn (TrackerPlan $record): bool => $record->is_active)
                    ->action(function (Action $action, TrackerPlan $record): void {
                        try {
                            self::setActive($record, false);
                            Notification::make()->success()->title(__('Тариф архивирован'))->send();
                        } catch (ValidationException $exception) {
                            $mapped = FormValidationFeedback::forAction($exception, $action);
                            FormValidationFeedback::notifyValidation($mapped, __('Не удалось выполнить действие. Попробуйте ещё раз.'));
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
                    ->visible(fn (TrackerPlan $record): bool => ! $record->is_active)
                    ->action(function (Action $action, TrackerPlan $record): void {
                        try {
                            self::setActive($record, true);
                            Notification::make()->success()->title(__('Тариф возвращён из архива'))->send();
                        } catch (ValidationException $exception) {
                            $mapped = FormValidationFeedback::forAction($exception, $action);
                            FormValidationFeedback::notifyValidation($mapped, __('Не удалось выполнить действие. Попробуйте ещё раз.'));
                            throw $mapped;
                        } catch (Throwable $exception) {
                            $mapped = FormValidationFeedback::unexpectedForAction($exception, $action);
                            FormValidationFeedback::notifyValidation($mapped);
                            throw $mapped;
                        }
                    }),
            ])
            ->emptyStateHeading(__('Тарифов пока нет'))
            ->emptyStateDescription(__('Добавьте первый тариф, чтобы предложить клиентам доступ к трекеру.'));
    }

    private static function setActive(TrackerPlan $record, bool $isActive): TrackerPlan
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(SetTrackerPlanActive::class)->handle($actor, $record, $isActive);
    }
}
