<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Contracts\ScenarioRecipientResolver;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Exceptions\FeedbackMiniAppConfigurationException;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioConditionSet;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioIdempotencyKey;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRecipient;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class MaterializeScenarioEvent
{
    public function __construct(
        private readonly ScenarioContextFactory $contextFactory,
        private readonly ConditionEvaluatorRegistry $conditions,
        private readonly ScenarioRecipientResolver $recipients,
        private readonly B2bSalesCallReadyGuard $b2bReadyGuard,
        private readonly BookingConfirmedGuard $bookingConfirmedGuard,
        private readonly BookingChangedGuard $bookingChangedGuard,
    ) {}

    public function handle(int $scenarioEventId): void
    {
        $event = $this->claim($scenarioEventId);

        if ($event === null) {
            return;
        }

        try {
            DB::transaction(function () use ($event): void {
                $context = $this->contextFactory->evaluationContext($event);
                if ($event->event_name->value === 'b2b.sales_call.ready'
                    && ! $this->b2bReadyGuard->allows($event, $context->b2bSalesCall)) {
                    $event->forceFill([
                        'status' => ScenarioEventStatus::Processed,
                        'processing_started_at' => null,
                        'processed_at' => now(),
                        'last_error_code' => 'b2b_sales_call_changed',
                    ])->save();

                    return;
                }
                if ($event->event_name->value === 'booking.confirmed'
                    && ! $this->bookingConfirmedGuard->allows($event, $context->booking)) {
                    if ($this->bookingConfirmedGuard->waitsForMeeting($context->booking)) {
                        $event->forceFill([
                            'status' => ScenarioEventStatus::Pending,
                            'available_at' => now()->addSeconds((int) config('scenarios.events.retry_after_seconds', 60)),
                            'processing_started_at' => null,
                            'processed_at' => null,
                            'last_error_code' => 'booking_meeting_pending',
                        ])->save();

                        return;
                    }

                    $event->forceFill([
                        'status' => ScenarioEventStatus::Processed,
                        'processing_started_at' => null,
                        'processed_at' => now(),
                        'last_error_code' => $context->booking?->visit_format === VisitFormat::Online
                            && $context->booking->meeting_link_mode?->value === 'auto'
                            ? 'booking_meeting_unavailable'
                            : 'booking_changed',
                    ])->save();

                    return;
                }
                if (in_array($event->event_name, [ScenarioEventType::BookingRescheduled, ScenarioEventType::BookingCancelled], true)
                    && ! $this->bookingChangedGuard->allows($event, $context->booking)) {
                    if ($event->event_name === ScenarioEventType::BookingRescheduled
                        && $this->bookingChangedGuard->waitsForMeeting($context->booking)) {
                        $event->forceFill([
                            'status' => ScenarioEventStatus::Pending,
                            'available_at' => now()->addSeconds((int) config('scenarios.events.retry_after_seconds', 60)),
                            'processing_started_at' => null,
                            'processed_at' => null,
                            'last_error_code' => 'booking_meeting_pending',
                        ])->save();

                        return;
                    }

                    $event->forceFill([
                        'status' => ScenarioEventStatus::Processed,
                        'processing_started_at' => null,
                        'processed_at' => now(),
                        'last_error_code' => 'booking_changed',
                    ])->save();

                    return;
                }
                $rules = ScenarioRule::query()
                    ->where('organization_id', $event->organization_id)
                    ->where('trigger_event', $event->event_name->value)
                    ->where('is_enabled', true)
                    ->where('system_managed', false)
                    ->with(['templateVersion.template'])
                    ->orderBy('id')
                    ->get();

                foreach ($rules as $rule) {
                    $this->materializeRule($event, $context, $rule);
                }

                $event->forceFill([
                    'status' => ScenarioEventStatus::Processed,
                    'processing_started_at' => null,
                    'processed_at' => now(),
                    'last_error_code' => null,
                ])->save();
            });
        } catch (Throwable $exception) {
            $this->recordFailure($event->getKey(), $exception);

            if ($this->shouldRetry($event->attempt_count)) {
                throw $exception;
            }
        }
    }

    private function materializeRule(ScenarioEvent $event, ScenarioEvaluationContext $context, ScenarioRule $rule): void
    {
        $template = $rule->templateVersion;

        if ($template === null
            || $template->status !== NotificationTemplateStatus::Published
            || $template->template === null
            || ! $template->template->is_active) {
            return;
        }

        $scheduledFor = CarbonImmutable::parse((string) $event->occurred_at)
            ->utc()
            ->addSeconds($rule->delay_unit->toSeconds($rule->delay_value));
        $evaluationContext = $context->withEvaluationEndsAt($scheduledFor);

        if (! $this->conditions->matches(ScenarioConditionSet::from($rule->conditions), $evaluationContext)) {
            return;
        }

        if ($event->event_name->value === 'finance.obligation.created'
            && ! $this->contextFactory->financeDebtIsCurrent($evaluationContext)) {
            return;
        }

        foreach ($this->recipients->resolve($rule, $event) as $recipient) {
            $this->materializeRecipient($event, $evaluationContext, $rule, $template, $recipient, $scheduledFor);
        }
    }

    private function materializeRecipient(
        ScenarioEvent $event,
        ScenarioEvaluationContext $context,
        ScenarioRule $rule,
        NotificationTemplateVersion $template,
        ScenarioRecipient $recipient,
        CarbonImmutable $scheduledFor,
    ): void {
        $materializationKey = ScenarioIdempotencyKey::materialization(
            (int) $event->organization_id,
            (int) $event->getKey(),
            (int) $rule->getKey(),
            $recipient->key(),
            1,
        );
        $timestamp = now();
        try {
            $renderContext = $this->contextFactory->renderContext(
                $context,
                $recipient,
                $template->template?->template_key === 'booking-completed-feedback',
            );
        } catch (FeedbackMiniAppConfigurationException) {
            $renderContext = $this->contextFactory->renderContext($context, $recipient);
            $renderContext['feedback'] = [
                'url' => null,
                'configuration_error' => FeedbackMiniAppConfigurationException::ERROR_CODE,
            ];
        }

        DB::table('scenario_actions')->insertOrIgnore([
            'organization_id' => $event->organization_id,
            'scenario_event_id' => $event->getKey(),
            'scenario_rule_id' => $rule->getKey(),
            'recipient_type' => $recipient->type,
            'client_id' => $recipient->clientId,
            'recipient_user_id' => $recipient->userId,
            'template_version_id' => $template->getKey(),
            'trigger_event' => $event->event_name->value,
            'rule_version' => $rule->version,
            'condition_snapshot' => json_encode($rule->conditions, JSON_THROW_ON_ERROR),
            'sequence_number' => 1,
            'max_occurrences' => $rule->max_occurrences,
            'repeat_interval_value' => $rule->repeat_interval_value,
            'repeat_interval_unit' => $rule->repeat_interval_unit?->value,
            'purpose' => $rule->purpose->value,
            'channel_priority' => json_encode($rule->channel_priority, JSON_THROW_ON_ERROR),
            'render_context' => json_encode($renderContext, JSON_THROW_ON_ERROR),
            'materialization_key' => $materializationKey,
            'scheduled_for' => $scheduledFor,
            'status' => ScenarioActionStatus::Scheduled->value,
            'attempt_count' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $actionId = DB::table('scenario_actions')
            ->where('organization_id', $event->organization_id)
            ->where('materialization_key', $materializationKey)
            ->value('id');

        if ($actionId === null) {
            throw new ModelNotFoundException;
        }

        foreach ($rule->channel_priority as $priority => $channel) {
            DB::table('scenario_deliveries')->insertOrIgnore([
                'organization_id' => $event->organization_id,
                'scenario_action_id' => $actionId,
                'channel' => $channel,
                'priority' => $priority + 1,
                'status' => 'pending',
                'idempotency_key' => ScenarioIdempotencyKey::delivery((int) $event->organization_id, (int) $actionId, $channel),
                'attempt_count' => 0,
                'next_attempt_at' => $scheduledFor,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    private function claim(int $scenarioEventId): ?ScenarioEvent
    {
        return DB::transaction(function () use ($scenarioEventId): ?ScenarioEvent {
            $event = ScenarioEvent::query()->lockForUpdate()->find($scenarioEventId);

            if ($event === null || in_array($event->status, [ScenarioEventStatus::Processed, ScenarioEventStatus::Failed], true)) {
                return null;
            }

            $now = CarbonImmutable::now();
            $staleAt = $now->subSeconds((int) config('scenarios.scheduler.stale_after_seconds', 300));

            if ($event->status === ScenarioEventStatus::Processing
                && $event->processing_started_at !== null
                && CarbonImmutable::parse((string) $event->processing_started_at)->greaterThan($staleAt)) {
                return null;
            }

            if ($event->available_at !== null && CarbonImmutable::parse((string) $event->available_at)->greaterThan($now)) {
                return null;
            }

            if ($event->attempt_count >= (int) config('scenarios.events.max_attempts', 5)) {
                $event->forceFill([
                    'status' => ScenarioEventStatus::Failed,
                    'processing_started_at' => null,
                    'last_error_code' => 'max_attempts_exceeded',
                ])->save();

                return null;
            }

            $event->forceFill([
                'status' => ScenarioEventStatus::Processing,
                'attempt_count' => $event->attempt_count + 1,
                'processing_started_at' => $now,
            ])->save();

            return $event->refresh();
        });
    }

    private function recordFailure(int $scenarioEventId, Throwable $exception): void
    {
        DB::transaction(function () use ($scenarioEventId, $exception): void {
            $event = ScenarioEvent::query()->lockForUpdate()->find($scenarioEventId);

            if ($event === null || $event->status !== ScenarioEventStatus::Processing) {
                return;
            }

            $retry = $this->shouldRetry($event->attempt_count);
            $event->forceFill([
                'status' => $retry ? ScenarioEventStatus::Retryable : ScenarioEventStatus::Failed,
                'available_at' => $retry ? now()->addSeconds((int) config('scenarios.events.retry_after_seconds', 60)) : $event->available_at,
                'processing_started_at' => null,
                'last_error_code' => $this->errorCode($exception),
            ])->save();
        });
    }

    private function shouldRetry(int $attemptCount): bool
    {
        return $attemptCount < (int) config('scenarios.events.max_attempts', 5);
    }

    private function errorCode(Throwable $exception): string
    {
        if ($exception instanceof InvalidArgumentException) {
            return 'invalid_event_configuration';
        }

        if ($exception instanceof ModelNotFoundException) {
            return 'required_context_missing';
        }

        return 'materialization_error';
    }
}
