<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\ValueObjects\NotificationActionButton;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Scenarios\Domain\Contracts\NotificationTemplateRenderer;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioDeliveryStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Exceptions\FeedbackMiniAppConfigurationException;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioDelivery;
use App\Modules\Scenarios\Domain\Models\ScenarioDeliveryAttempt;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioConditionSet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class ExecuteScenarioAction
{
    public function __construct(
        private readonly ScenarioContextFactory $contextFactory,
        private readonly ConditionEvaluatorRegistry $conditions,
        private readonly ScenarioChannelIdentityResolver $identities,
        private readonly NotificationChannelRegistry $channels,
        private readonly NotificationTemplateRenderer $renderer,
        private readonly ScheduleNextScenarioAction $nextActions,
        private readonly B2bSalesCallReadyGuard $b2bReadyGuard,
        private readonly BookingConfirmedGuard $bookingConfirmedGuard,
        private readonly BookingChangedGuard $bookingChangedGuard,
    ) {}

    public function handle(int $scenarioActionId): void
    {
        $action = $this->claimAction($scenarioActionId);

        if ($action === null) {
            return;
        }

        if (! $this->isEligible($action)) {
            $this->suppress(
                $action->getKey(),
                $this->changeReason($action->trigger_event->value),
            );

            return;
        }

        while (true) {
            $delivery = $this->claimDelivery($action->getKey());

            if ($delivery === null) {
                $this->reconcile($action->getKey());

                return;
            }

            $result = $this->send($action->getKey(), $delivery);
            $outcome = $this->finalizeDelivery($delivery->getKey(), $result);

            if (! in_array($outcome, [NotificationDeliveryOutcome::PermanentFailure, NotificationDeliveryOutcome::Unavailable], true)) {
                return;
            }
        }
    }

    private function isEligible(ScenarioAction $action): bool
    {
        $currentRule = $action->rule;
        $event = $action->event;

        if ($currentRule === null || $event === null || ! $currentRule->is_enabled) {
            return false;
        }

        $context = $this->contextFactory->evaluationContext(
            $event,
            $action->scheduled_for === null
                ? null
                : CarbonImmutable::parse((string) $action->scheduled_for)->utc(),
        );

        if (! is_array($action->condition_snapshot)) {
            return false;
        }

        if ($event->event_name->value === 'finance.obligation.created'
            && ! $this->contextFactory->financeDebtIsCurrent($context)) {
            return false;
        }

        if ($event->event_name->value === 'b2b.sales_call.ready'
            && ! $this->b2bReadyGuard->allows($event, $context->b2bSalesCall, $action->render_context)) {
            return false;
        }

        if ($event->event_name->value === 'booking.confirmed'
            && ! $this->bookingConfirmedGuard->allows($event, $context->booking, $action->render_context)) {
            return false;
        }

        if (in_array($event->event_name, [ScenarioEventType::BookingRescheduled, ScenarioEventType::BookingCancelled], true)
            && ! $this->bookingChangedGuard->allows($event, $context->booking, $action->render_context)) {
            return false;
        }

        try {
            $conditionSnapshot = ScenarioConditionSet::from($action->condition_snapshot);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $this->conditions->matches($conditionSnapshot, $context);
    }

    private function send(int $actionId, ScenarioDelivery $delivery): NotificationDeliveryResult
    {
        try {
            $action = ScenarioAction::query()
                ->whereKey($actionId)
                ->where('organization_id', $delivery->organization_id)
                ->with('templateVersion.template')
                ->firstOrFail();
            $event = $action->event()->first();
            if ($event === null
                || ($event->event_name->value === 'b2b.sales_call.ready'
                    && ! $this->b2bReadyGuard->allows($event, renderContext: $action->render_context))
                || ($event->event_name->value === 'booking.confirmed'
                    && ! $this->bookingConfirmedGuard->allows($event, renderContext: $action->render_context))
                || (in_array($event->event_name, [ScenarioEventType::BookingRescheduled, ScenarioEventType::BookingCancelled], true)
                    && ! $this->bookingChangedGuard->allows($event, renderContext: $action->render_context))) {
                return NotificationDeliveryResult::suppressed($this->changeReason($event?->event_name->value));
            }
            $identity = $this->identities->resolve($action, $delivery->channel);

            if ($identity === null) {
                return NotificationDeliveryResult::unavailable('verified_identity_unavailable');
            }

            $channel = $this->channels->get($delivery->channel);

            if ($channel === null || ! $channel->capabilities()->supportsProactiveDelivery) {
                return NotificationDeliveryResult::unavailable('channel_unavailable');
            }

            $template = $action->templateVersion;

            if ($template === null || $template->template === null || ! $template->template->is_active
                || $template->status === NotificationTemplateStatus::Archived
                || $template->template->purpose !== $action->purpose->value
                || $action->purpose === ScenarioRulePurpose::Marketing) {
                return NotificationDeliveryResult::permanentFailure('template_unavailable');
            }

            $locale = $template->template->locale;
            $webAppUrl = null;
            if ($template->template->template_key === 'booking-completed-feedback') {
                try {
                    $webAppUrl = $this->contextFactory->feedbackUrl();
                } catch (FeedbackMiniAppConfigurationException) {
                    return NotificationDeliveryResult::unavailable(FeedbackMiniAppConfigurationException::ERROR_CODE);
                }

                $feedbackContext = $action->render_context['feedback'] ?? null;
                if (! is_array($feedbackContext) || ($feedbackContext['url'] ?? null) !== $webAppUrl) {
                    return NotificationDeliveryResult::unavailable(FeedbackMiniAppConfigurationException::ERROR_CODE);
                }
            }
            $rendered = $this->renderer->render($template, $action->render_context, $locale);
            $actionButton = $this->actionButton($action, $rendered->locale);

            if ($actionButton !== null && ! $channel->capabilities()->supportsInlineButtons) {
                return NotificationDeliveryResult::unavailable('inline_buttons_unavailable');
            }

            $event = $action->event()->first();
            if ($event === null
                || ($event->event_name->value === 'b2b.sales_call.ready'
                    && ! $this->b2bReadyGuard->allows($event, renderContext: $action->render_context))
                || ($event->event_name->value === 'booking.confirmed'
                    && ! $this->bookingConfirmedGuard->allows($event, renderContext: $action->render_context))
                || (in_array($event->event_name, [ScenarioEventType::BookingRescheduled, ScenarioEventType::BookingCancelled], true)
                    && ! $this->bookingChangedGuard->allows($event, renderContext: $action->render_context))) {
                return NotificationDeliveryResult::suppressed($this->changeReason($event?->event_name->value));
            }

            return $channel->send(new NotificationMessage(
                recipientExternalId: $identity->externalId,
                body: $rendered->body,
                subject: $rendered->subject,
                locale: $rendered->locale,
                idempotencyKey: $delivery->idempotency_key,
                webAppUrl: $webAppUrl,
                actionButton: $actionButton,
            ));
        } catch (InvalidArgumentException) {
            return NotificationDeliveryResult::permanentFailure('template_rendering_error');
        } catch (ModelNotFoundException) {
            return NotificationDeliveryResult::permanentFailure('delivery_context_missing');
        } catch (Throwable) {
            return NotificationDeliveryResult::retryable('delivery_execution_error');
        }
    }

    private function actionButton(ScenarioAction $action, string $locale): ?NotificationActionButton
    {
        if ($action->recipient_type !== 'client') {
            return null;
        }

        $url = match ($action->trigger_event->value) {
            'b2b.sales_call.ready' => $action->render_context['sales_call']['join_url'] ?? null,
            'booking.confirmed' => $action->render_context['booking']['meeting_url'] ?? null,
            'booking.rescheduled' => $action->render_context['booking']['meeting_url'] ?? null,
            default => null,
        };

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return new NotificationActionButton(
            text: $this->isRussian($locale) ? 'Подключиться к встрече' : 'Join meeting',
            url: $url,
        );
    }

    private function changeReason(?string $eventName): string
    {
        return match ($eventName) {
            'b2b.sales_call.ready' => 'b2b_sales_call_changed',
            'booking.confirmed', 'booking.rescheduled', 'booking.cancelled' => 'booking_changed',
            default => 'current_conditions_not_met',
        };
    }

    private function isRussian(string $locale): bool
    {
        return str_starts_with(strtolower($locale), 'ru');
    }

    private function claimAction(int $scenarioActionId): ?ScenarioAction
    {
        return DB::transaction(function () use ($scenarioActionId): ?ScenarioAction {
            $action = ScenarioAction::query()->lockForUpdate()->find($scenarioActionId);

            if ($action === null || $action->status->isTerminal()) {
                return null;
            }

            $now = CarbonImmutable::now();
            $staleAt = $now->subSeconds((int) config('scenarios.scheduler.stale_after_seconds', 300));

            if ($action->status === ScenarioActionStatus::Processing
                && $action->processing_started_at !== null
                && CarbonImmutable::parse((string) $action->processing_started_at)->greaterThan($staleAt)) {
                return null;
            }

            if ($action->scheduled_for !== null && CarbonImmutable::parse((string) $action->scheduled_for)->greaterThan($now)) {
                return null;
            }

            if ($action->status === ScenarioActionStatus::Processing) {
                $this->recoverStaleAction($action);

                return null;
            }

            $action->forceFill([
                'status' => ScenarioActionStatus::Processing,
                'attempt_count' => $action->attempt_count + 1,
                'processing_started_at' => $now,
            ])->save();

            return $action->refresh();
        });
    }

    private function claimDelivery(int $actionId): ?ScenarioDelivery
    {
        return DB::transaction(function () use ($actionId): ?ScenarioDelivery {
            $action = ScenarioAction::query()->lockForUpdate()->find($actionId);

            if ($action === null || $action->status !== ScenarioActionStatus::Processing) {
                return null;
            }

            $now = CarbonImmutable::now();
            $deliveries = ScenarioDelivery::query()
                ->where('organization_id', $action->organization_id)
                ->where('scenario_action_id', $action->getKey())
                ->orderBy('priority')
                ->lockForUpdate()
                ->get();

            foreach ($deliveries as $delivery) {
                if ($delivery->status->isTerminal()) {
                    continue;
                }

                if ($delivery->status === ScenarioDeliveryStatus::Processing) {
                    return null;
                }

                if ($delivery->next_attempt_at !== null
                    && CarbonImmutable::parse((string) $delivery->next_attempt_at)->greaterThan($now)) {
                    return null;
                }

                $delivery->forceFill([
                    'status' => ScenarioDeliveryStatus::Processing,
                    'attempt_count' => $delivery->attempt_count + 1,
                    'processing_started_at' => $now,
                ])->save();

                $attempt = new ScenarioDeliveryAttempt;
                $attempt->forceFill([
                    'organization_id' => $delivery->organization_id,
                    'scenario_delivery_id' => $delivery->getKey(),
                    'attempt_number' => $delivery->attempt_count,
                    'outcome' => NotificationDeliveryOutcome::Unknown,
                    'attempted_at' => $now,
                ])->save();

                return $delivery->refresh();
            }

            return null;
        });
    }

    private function finalizeDelivery(int $deliveryId, NotificationDeliveryResult $result): NotificationDeliveryOutcome
    {
        return DB::transaction(function () use ($deliveryId, $result): NotificationDeliveryOutcome {
            $delivery = ScenarioDelivery::query()->lockForUpdate()->findOrFail($deliveryId);

            if ($delivery->status !== ScenarioDeliveryStatus::Processing) {
                return $result->outcome;
            }

            $outcome = $result->outcome === NotificationDeliveryOutcome::InFlight
                ? NotificationDeliveryOutcome::Unknown
                : $result->outcome;

            $attempt = ScenarioDeliveryAttempt::query()
                ->where('organization_id', $delivery->organization_id)
                ->where('scenario_delivery_id', $delivery->getKey())
                ->where('attempt_number', $delivery->attempt_count)
                ->lockForUpdate()
                ->firstOrFail();
            $attempt->forceFill([
                'outcome' => $outcome,
                'error_code' => $this->safeCode($outcome === NotificationDeliveryOutcome::Unknown ? 'delivery_outcome_unknown' : $result->errorCode),
                'provider_reference' => $this->safeReference($result->providerReference),
            ])->save();

            $retryable = $outcome === NotificationDeliveryOutcome::Retryable
                && $delivery->attempt_count < (int) config('scenarios.deliveries.max_attempts', 3);
            $effectiveOutcome = $outcome === NotificationDeliveryOutcome::Retryable && ! $retryable
                ? NotificationDeliveryOutcome::PermanentFailure
                : $outcome;
            $deliveryStatus = $outcome === NotificationDeliveryOutcome::Retryable && ! $retryable
                ? ScenarioDeliveryStatus::PermanentFailure
                : $this->deliveryStatus($outcome);

            $delivery->forceFill([
                'status' => $deliveryStatus,
                'processing_started_at' => null,
                'delivered_at' => $outcome === NotificationDeliveryOutcome::Delivered ? now() : null,
                'next_attempt_at' => $retryable
                    ? now()->addSeconds((int) config('scenarios.deliveries.retry_after_seconds', 300))
                    : null,
                'last_error_code' => $this->safeCode($outcome === NotificationDeliveryOutcome::Unknown ? 'delivery_outcome_unknown' : $result->errorCode),
                'terminal_reason' => $this->terminalReason($outcome, $delivery->attempt_count),
                'provider_reference' => $this->safeReference($result->providerReference),
            ])->save();

            $action = ScenarioAction::query()
                ->where('organization_id', $delivery->organization_id)
                ->whereKey($delivery->scenario_action_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($outcome === NotificationDeliveryOutcome::Delivered) {
                $action->forceFill([
                    'status' => ScenarioActionStatus::Delivered,
                    'processing_started_at' => null,
                    'delivered_at' => now(),
                    'terminal_reason' => null,
                ])->save();
                $this->nextActions->handle($action->refresh());
            } elseif ($outcome === NotificationDeliveryOutcome::Retryable
                && $delivery->attempt_count < (int) config('scenarios.deliveries.max_attempts', 3)) {
                $action->forceFill([
                    'status' => ScenarioActionStatus::Retryable,
                    'processing_started_at' => null,
                    'scheduled_for' => $delivery->next_attempt_at,
                    'terminal_reason' => null,
                ])->save();
            } elseif ($outcome === NotificationDeliveryOutcome::Suppressed) {
                $action->forceFill([
                    'status' => ScenarioActionStatus::Suppressed,
                    'processing_started_at' => null,
                    'suppressed_at' => now(),
                    'terminal_reason' => 'provider_suppressed',
                ])->save();
            }

            return $effectiveOutcome;
        });
    }

    private function reconcile(int $actionId): void
    {
        DB::transaction(function () use ($actionId): void {
            $action = ScenarioAction::query()->lockForUpdate()->find($actionId);

            if ($action === null || $action->status !== ScenarioActionStatus::Processing) {
                return;
            }

            $this->reconcileLockedAction($action);
        });
    }

    private function suppress(int $actionId, string $reason): void
    {
        DB::transaction(function () use ($actionId, $reason): void {
            $action = ScenarioAction::query()->lockForUpdate()->find($actionId);

            if ($action === null || $action->status->isTerminal()) {
                return;
            }

            ScenarioDelivery::query()
                ->where('organization_id', $action->organization_id)
                ->where('scenario_action_id', $action->getKey())
                ->whereIn('status', [
                    ScenarioDeliveryStatus::Pending->value,
                    ScenarioDeliveryStatus::Retryable->value,
                    ScenarioDeliveryStatus::Processing->value,
                ])
                ->update([
                    'status' => ScenarioDeliveryStatus::Suppressed->value,
                    'processing_started_at' => null,
                    'terminal_reason' => $reason,
                    'updated_at' => now(),
                ]);
            $action->forceFill([
                'status' => ScenarioActionStatus::Suppressed,
                'processing_started_at' => null,
                'suppressed_at' => now(),
                'terminal_reason' => $reason,
            ])->save();
        });
    }

    private function recoverStaleAction(ScenarioAction $action): void
    {
        $deliveryIds = ScenarioDelivery::query()
            ->where('organization_id', $action->organization_id)
            ->where('scenario_action_id', $action->getKey())
            ->where('status', ScenarioDeliveryStatus::Processing->value)
            ->lockForUpdate()
            ->pluck('id');

        if ($deliveryIds->isEmpty()) {
            $this->reconcileLockedAction($action);

            return;
        }

        DB::table('scenario_delivery_attempts')
            ->where('organization_id', $action->organization_id)
            ->whereIn('scenario_delivery_id', $deliveryIds)
            ->where('outcome', NotificationDeliveryOutcome::Unknown->value)
            ->update(['error_code' => 'worker_lost_before_outcome']);
        ScenarioDelivery::query()
            ->whereIn('id', $deliveryIds)
            ->update([
                'status' => ScenarioDeliveryStatus::PermanentFailure->value,
                'processing_started_at' => null,
                'terminal_reason' => 'delivery_outcome_unknown',
                'last_error_code' => 'worker_lost_before_outcome',
                'updated_at' => now(),
            ]);
        $action->forceFill([
            'status' => ScenarioActionStatus::Failed,
            'processing_started_at' => null,
            'terminal_reason' => 'delivery_outcome_unknown',
        ])->save();
    }

    private function reconcileLockedAction(ScenarioAction $action): void
    {
        $deliveries = ScenarioDelivery::query()
            ->where('organization_id', $action->organization_id)
            ->where('scenario_action_id', $action->getKey())
            ->orderBy('priority')
            ->lockForUpdate()
            ->get();
        $open = $deliveries->filter(static fn (ScenarioDelivery $delivery): bool => ! $delivery->status->isTerminal());

        if ($open->isNotEmpty()) {
            $nextAttempt = $open
                ->map(static fn (ScenarioDelivery $delivery): CarbonImmutable => $delivery->next_attempt_at === null
                    ? CarbonImmutable::now()
                    : CarbonImmutable::parse((string) $delivery->next_attempt_at))
                ->min();
            $action->forceFill([
                'status' => ScenarioActionStatus::Retryable,
                'processing_started_at' => null,
                'scheduled_for' => $nextAttempt,
                'terminal_reason' => null,
            ])->save();

            return;
        }

        $allUnavailable = $deliveries->isNotEmpty()
            && $deliveries->every(static fn (ScenarioDelivery $delivery): bool => $delivery->status === ScenarioDeliveryStatus::Unavailable);
        $action->forceFill([
            'status' => $allUnavailable ? ScenarioActionStatus::Suppressed : ScenarioActionStatus::Failed,
            'processing_started_at' => null,
            'suppressed_at' => $allUnavailable ? now() : null,
            'terminal_reason' => $allUnavailable ? 'no_available_channel' : 'all_channels_failed',
        ])->save();
    }

    private function deliveryStatus(NotificationDeliveryOutcome $outcome): ScenarioDeliveryStatus
    {
        return match ($outcome) {
            NotificationDeliveryOutcome::Delivered => ScenarioDeliveryStatus::Delivered,
            NotificationDeliveryOutcome::Retryable => ScenarioDeliveryStatus::Retryable,
            NotificationDeliveryOutcome::PermanentFailure => ScenarioDeliveryStatus::PermanentFailure,
            NotificationDeliveryOutcome::Unavailable => ScenarioDeliveryStatus::Unavailable,
            NotificationDeliveryOutcome::Suppressed, NotificationDeliveryOutcome::Unknown => ScenarioDeliveryStatus::Suppressed,
            NotificationDeliveryOutcome::InFlight => ScenarioDeliveryStatus::Suppressed,
        };
    }

    private function terminalReason(NotificationDeliveryOutcome $outcome, int $attemptCount): ?string
    {
        return match ($outcome) {
            NotificationDeliveryOutcome::Delivered => null,
            NotificationDeliveryOutcome::Retryable => $attemptCount >= (int) config('scenarios.deliveries.max_attempts', 3)
                ? 'retryable_attempts_exhausted'
                : null,
            NotificationDeliveryOutcome::PermanentFailure => 'provider_permanent_failure',
            NotificationDeliveryOutcome::Unavailable => 'channel_unavailable',
            NotificationDeliveryOutcome::Suppressed => 'suppressed',
            NotificationDeliveryOutcome::Unknown => 'delivery_outcome_unknown',
            NotificationDeliveryOutcome::InFlight => 'delivery_outcome_unknown',
        };
    }

    private function safeCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        return preg_match('/^[a-z0-9_.:-]{1,120}$/', $value) === 1 ? $value : 'provider_error';
    }

    private function safeReference(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::limit(trim($value), 191, '');
        $value = preg_replace('/[^a-zA-Z0-9._:-]/', '_', $value) ?? '';

        return $value === '' ? null : $value;
    }
}
