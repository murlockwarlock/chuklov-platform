<?php

namespace App\Filament\Resources\ScenarioRules\Tables;

use App\Filament\Support\FormValidationFeedback;
use App\Filament\Support\RichTextPresentation;
use App\Models\User;
use App\Modules\Scenarios\Application\SetScenarioRuleEnabled;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ScenarioRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('name')
                    ->label(__('Авто-сообщение'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('trigger_event')
                    ->label(__('Когда'))
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::eventLabel($state)),
                TextColumn::make('recipient_summary')
                    ->label(__('Кому'))
                    ->state(fn (ScenarioRule $record): string => self::recipientLabel($record)),
                TextColumn::make('message_summary')
                    ->label(__('Что отправить'))
                    ->state(fn (ScenarioRule $record): string => self::messageLabel($record))
                    ->wrap(),
                IconColumn::make('is_enabled')
                    ->label(__('Включено'))
                    ->boolean()
                    ->sortable(),
            ])
            ->emptyStateHeading(__('Авто-сообщений пока нет'))
            ->emptyStateDescription(__('Создайте авто-сообщение, чтобы отправлять клиенту нужный текст после события.'))
            ->recordActions([
                ViewAction::make()
                    ->label(__('Открыть'))
                    ->icon('heroicon-o-eye')
                    ->iconButton()
                    ->tooltip(__('Открыть авто-сообщение')),
                EditAction::make()
                    ->label(__('Редактировать'))
                    ->icon('heroicon-o-pencil')
                    ->iconButton()
                    ->tooltip(__('Редактировать авто-сообщение')),
                Action::make('disable')
                    ->label(__('Отключить'))
                    ->color('warning')
                    ->icon('heroicon-o-pause')
                    ->requiresConfirmation()
                    ->modalHeading(__('Отключить авто-сообщение?'))
                    ->modalDescription(__('Автоматическая отправка прекратится. Настройки и история уже отправленных сообщений останутся.'))
                    ->visible(fn (ScenarioRule $record): bool => $record->is_enabled)
                    ->action(function (Action $action, ScenarioRule $record): void {
                        try {
                            self::setEnabled($record, false);
                            Notification::make()->success()->title(__('Авто-сообщение отключено'))->send();
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
                Action::make('enable')
                    ->label(__('Включить'))
                    ->color('success')
                    ->icon('heroicon-o-play')
                    ->requiresConfirmation()
                    ->visible(fn (ScenarioRule $record): bool => ! $record->is_enabled)
                    ->action(function (Action $action, ScenarioRule $record): void {
                        try {
                            self::setEnabled($record, true);
                            Notification::make()->success()->title(__('Авто-сообщение включено'))->send();
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
            ]);
    }

    private static function setEnabled(ScenarioRule $record, bool $isEnabled): ScenarioRule
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(SetScenarioRuleEnabled::class)->handle($actor, $record, $isEnabled);
    }

    private static function eventLabel(mixed $event): string
    {
        $value = $event instanceof BackedEnum ? $event->value : (string) $event;

        return match ($value) {
            ScenarioEventType::BookingCreated->value => __('После новой записи'),
            ScenarioEventType::BookingConfirmed->value => __('После подтверждения'),
            ScenarioEventType::BookingRescheduled->value => __('После переноса'),
            ScenarioEventType::BookingCancelled->value => __('После отмены'),
            ScenarioEventType::BookingCompleted->value => __('После визита'),
            ScenarioEventType::OnboardingStarted->value => __('После начала оформления'),
            ScenarioEventType::FinancialObligationCreated->value => __('После появления задолженности'),
            ScenarioEventType::FinancialDebtReminderRequested->value => __('При напоминании о задолженности'),
            ScenarioEventType::SurveyCompleted->value => __('После теста'),
            ScenarioEventType::TestStagnationDetected->value => __('Если показатели не снижаются'),
            ScenarioEventType::B2bLeadSubmitted->value => __('После B2B-запроса'),
            ScenarioEventType::B2bSalesCallReady->value => __('Когда B2B-разговор готов'),
            ScenarioEventType::CompanionRequestedSpecialist->value => __('Когда клиент просит специалиста'),
            ScenarioEventType::CompanionSpecialistAttention->value => __('Когда сообщение требует внимания специалиста'),
            ScenarioEventType::CompanionFallbackFailed->value => __('Когда AI не смог ответить'),
            ScenarioEventType::BroadcastDeliveryFailed->value => __('При сбое операционной рассылки'),
            ScenarioEventType::ClientFeedbackSubmitted->value => __('После обратной связи клиента'),
            ScenarioEventType::PayoutRequested->value => __('При запросе выплаты партнёра'),
            ScenarioEventType::PayoutStatusChanged->value => __('При изменении статуса выплаты'),
            ScenarioEventType::HomeVisitChanged->value => __('При изменении выездного визита'),
            ScenarioEventType::AiEvaluationFailed->value => __('При сбое проверки AI'),
            ScenarioEventType::KnowledgeIngestionFailed->value => __('При ошибке обработки материала'),
            ScenarioEventType::ReferralLinkVisited->value => __('При переходе по реферальной ссылке'),
            ScenarioEventType::PaymentProviderEventPrepared->value => __('Устаревшее событие платёжного провайдера'),
            ScenarioEventType::PaymentSucceeded->value => __('После подтверждённой оплаты'),
            ScenarioEventType::PaymentFailed->value => __('При неуспешной оплате'),
            ScenarioEventType::PaymentInitiationUnavailable->value => __('Когда онлайн-оплата недоступна'),
            ScenarioEventType::PaymentReconciliationRequired->value => __('Когда платёж требует сверки'),
            ScenarioEventType::FulfillmentFailed->value => __('Если доступ не выдан'),
            ScenarioEventType::FulfillmentCompleted->value => __('Когда доступ выдан'),
            ScenarioEventType::ReferralRewardEarned->value => __('При начислении по партнёрской программе'),
            default => __('Событие'),
        };
    }

    private static function recipientLabel(ScenarioRule $record): string
    {
        return match ($record->recipient_strategy['type'] ?? null) {
            'client' => __('Клиент'),
            'assigned_specialist' => __('Специалист'),
            'members' => __('Выбранные сотрудники'),
            'roles' => __('Сотрудники по роли'),
            default => __('Не указано'),
        };
    }

    private static function messageLabel(ScenarioRule $record): string
    {
        $template = $record->templateVersion?->template;
        $body = RichTextPresentation::text($record->templateVersion?->body);

        return ($template?->name ?: __('Сообщение'))
            .($body === '' ? '' : ' · '.Str::limit($body, 70));
    }
}
