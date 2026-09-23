<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\Channels\Application\ResolveTelegramMiniAppEntry;
use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Commerce\Domain\Models\PurchaseFulfillment;
use App\Modules\Commerce\Domain\Models\PurchaseItem;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\PaymentGatewayEvent;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Referrals\Application\BuildClientReferralLink;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequest;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Exceptions\FeedbackMiniAppConfigurationException;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRecipient;
use App\Modules\Scheduling\Application\BookingDateTimeFormatter;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Surveys\Application\SurveyComparisonPresentation;
use App\Modules\Surveys\Domain\Enums\SurveyVersionStatus;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyComparison;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Tracker\Domain\Models\TrackerPlanVersion;
use App\Modules\Tracker\Domain\Models\TrackerTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ScenarioContextFactory
{
    public function __construct(
        private readonly BookingDateTimeFormatter $bookingDateTime,
        private readonly BuildClientReferralLink $referralLinks,
        private readonly SurveyComparisonPresentation $comparisonPresentation,
        private readonly PaymentPreVisitBookingEligibility $paymentEligibility,
    ) {}

    public function evaluationContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt = null): ScenarioEvaluationContext
    {
        return match ($event->event_name) {
            ScenarioEventType::BookingCreated => $this->bookingContext($event, $evaluationEndsAt),
            ScenarioEventType::BookingConfirmed => $this->bookingContext($event, $evaluationEndsAt),
            ScenarioEventType::BookingRescheduled => $this->bookingContext($event, $evaluationEndsAt),
            ScenarioEventType::BookingCancelled => $this->bookingContext($event, $evaluationEndsAt),
            ScenarioEventType::BookingRejected => $this->bookingContext($event, $evaluationEndsAt),
            ScenarioEventType::HomeVisitChanged => $this->bookingContext($event, $evaluationEndsAt),
            ScenarioEventType::BookingCompleted => $this->bookingContext($event, $evaluationEndsAt),
            ScenarioEventType::OnboardingStarted => $this->onboardingContext($event, $evaluationEndsAt),
            ScenarioEventType::FinancialObligationCreated,
            ScenarioEventType::FinancialDebtReminderRequested => $this->financialContext($event, $evaluationEndsAt),
            ScenarioEventType::PaymentSucceeded,
            ScenarioEventType::PaymentFailed,
            ScenarioEventType::PaymentInitiationUnavailable,
            ScenarioEventType::PaymentReconciliationRequired => $this->paymentContext($event, $evaluationEndsAt),
            ScenarioEventType::FulfillmentFailed,
            ScenarioEventType::FulfillmentCompleted => $this->fulfillmentContext($event, $evaluationEndsAt),
            ScenarioEventType::ReferralRewardEarned => $this->rewardContext($event, $evaluationEndsAt),
            ScenarioEventType::SurveyCompleted, ScenarioEventType::TestStagnationDetected => $this->surveyContext($event, $evaluationEndsAt),
            ScenarioEventType::B2bLeadSubmitted => $this->b2bLeadContext($event, $evaluationEndsAt),
            ScenarioEventType::B2bSalesCallReady => $this->b2bSalesCallContext($event, $evaluationEndsAt),
            ScenarioEventType::CompanionRequestedSpecialist => $this->companionContext($event, $evaluationEndsAt),
            ScenarioEventType::CompanionFallbackFailed => $this->companionContext($event, $evaluationEndsAt),
            default => $this->genericClientContext($event, $evaluationEndsAt),
        };
    }

    /** @return array<string, mixed> */
    public function renderContext(
        ScenarioEvaluationContext $context,
        ScenarioRecipient $recipient,
        bool $includeFeedbackUrl = false,
    ): array {
        if ($context->client === null && ! $this->allowsClientlessOperationalEvent($context->event->event_name)) {
            throw (new ModelNotFoundException)->setModel(Client::class);
        }

        $renderContext = [
            'client' => [
                'full_name' => $context->client === null ? '' : $this->clientDisplayName($context->client),
                'language' => strtolower((string) ($context->client instanceof Client ? $context->client->language : 'en')),
            ],
            'recipient_locale' => $recipient->locale,
        ];

        if ($recipient->type === 'client' && $context->client !== null) {
            try {
                $renderContext['referral_link'] = $this->referralLinks->handle($context->client);
            } catch (LogicException) {
            }
        }

        if ($recipient->type === 'internal' && $context->client !== null) {
            $renderContext['client']['telegram_contact'] = $this->clientTelegramContact($context->client);
            $renderContext['client']['telegram_profile_url'] = $this->clientTelegramProfileUrl($context->client);
        }

        if (in_array($context->event->event_name, [ScenarioEventType::CompanionRequestedSpecialist, ScenarioEventType::CompanionFallbackFailed], true)) {
            if (! $context->client instanceof Client) {
                throw (new ModelNotFoundException)->setModel(Client::class);
            }
            $renderContext['companion'] = [
                'escalation_id' => (int) ($context->event->payload['escalation_id'] ?? 0),
                'crm_url' => url('/admin/messages?client='.$context->client->getKey()),
                'reason' => (string) ($context->event->payload['reason'] ?? ''),
            ];
        }

        if (in_array($context->event->event_name, [ScenarioEventType::PayoutRequested, ScenarioEventType::PayoutStatusChanged], true)) {
            $requestId = $this->payloadId($context->event, 'payout_request_id');
            $request = ReferralPayoutRequest::query()
                ->where('organization_id', $context->event->organization_id)
                ->whereKey($requestId)
                ->with('beneficiary')
                ->first();
            if (! $request instanceof ReferralPayoutRequest) {
                throw (new ModelNotFoundException)->setModel(ReferralPayoutRequest::class);
            }
            $currency = CurrencyCode::tryFrom((string) $request->getRawOriginal('currency'));
            $amount = $currency === null
                ? '—'
                : Money::ofMinor((int) $request->amount_minor, $currency)->toDecimalString().' '.$currency->value;
            $processedAt = match ($request->status) {
                ReferralPayoutRequestStatus::Approved => $request->approved_at,
                ReferralPayoutRequestStatus::Rejected => $request->rejected_at,
                ReferralPayoutRequestStatus::Cancelled => $request->cancelled_at,
                ReferralPayoutRequestStatus::Paid => $request->paid_at,
                default => null,
            };
            $renderContext['payout'] = [
                'id' => (int) $request->getKey(),
                'amount' => $amount,
                'currency' => $currency instanceof CurrencyCode ? $currency->value : '',
                'status' => $request->status->value,
                'status_label' => $this->payoutStatusLabel($request->status, $recipient),
                'requested_at' => $request->requested_at->toIso8601String(),
                'processed_at' => $processedAt?->toIso8601String(),
                'reason' => $request->rejection_reason,
                'crm_url' => url('/admin/referral-payout-requests/'.$request->getKey()),
                'portal_url' => url('/portal/referrals'),
            ];
        }

        if ($context->event->event_name === ScenarioEventType::KnowledgeIngestionFailed) {
            $revision = KnowledgeRevision::query()
                ->where('organization_id', $context->event->organization_id)
                ->where('knowledge_source_id', $this->payloadId($context->event, 'source_id'))
                ->whereKey($this->payloadId($context->event, 'revision_id'))
                ->with('source')
                ->first();
            if (! $revision instanceof KnowledgeRevision || $revision->source === null) {
                throw (new ModelNotFoundException)->setModel(KnowledgeRevision::class);
            }
            $renderContext['knowledge'] = [
                'source_title' => $revision->source->title,
                'revision_version' => (int) $revision->version,
                'crm_url' => $recipient->type === 'internal'
                    ? url('/admin/knowledge-sources/'.$revision->source->getKey().'/edit')
                    : null,
            ];
        }

        if ($context->event->event_name === ScenarioEventType::ClientFeedbackSubmitted) {
            $feedbackId = $this->optionalPayloadId($context->event, 'feedback_submission_id');
            $renderContext['feedback'] = [
                'score' => (int) ($context->event->payload['score'] ?? 0),
                'has_internal_feedback' => (bool) ($context->event->payload['has_internal_feedback'] ?? false),
                'crm_url' => $recipient->type === 'internal' && $feedbackId !== null
                    ? url('/admin/feedback-submissions/'.$feedbackId)
                    : null,
            ];
        }

        if (in_array($context->event->event_name, [ScenarioEventType::TrackerDailyTaskAssigned, ScenarioEventType::TrackerWeeklyTaskAssigned], true)) {
            $taskId = $this->payloadId($context->event, 'task_id');
            $task = TrackerTask::query()
                ->where('organization_id', $context->event->organization_id)
                ->whereKey($taskId)
                ->first();
            if (! $task instanceof TrackerTask) {
                throw (new ModelNotFoundException)->setModel(TrackerTask::class);
            }
            $renderContext['tracker'] = [
                'task_title' => $task->title,
                'task_type' => $task->task_type->value,
                'frequency' => $task->frequency->value,
                'portal_url' => route('portal.tracker'),
            ];
        }

        if ($context->booking !== null) {
            $bookingDateTime = $recipient->type === 'internal'
                ? $this->bookingDateTime->forSpecialist($context->booking)
                : $this->bookingDateTime->forClient($context->booking);
            $locationSnapshot = $context->booking->locationSnapshot();
            $renderContext['booking'] = [
                'id' => (int) $context->booking->getKey(),
                'event_version' => (int) $context->booking->event_version,
                'status' => $context->booking->status->value,
                'visit_format' => $context->booking->visit_format->value,
                'visit_format_label' => $this->visitFormatLabel($context->booking->visit_format->value, $recipient->locale),
                'service_name' => $context->booking->service->name,
                'specialist_name' => $context->booking->specialist->display_name,
                'location' => $locationSnapshot['address'] ?? $context->booking->location,
                'location_label' => $this->locationLabel($context->booking, $locationSnapshot, $recipient->locale, $recipient->type === 'internal'),
                'visit_details' => $this->visitDetails($context->booking, $locationSnapshot, $recipient->locale, $recipient->type === 'internal'),
                'location_name' => $locationSnapshot['name'] ?? null,
                'location_address' => $locationSnapshot['address'] ?? $context->booking->location,
                'location_timezone' => $locationSnapshot['timezone'] ?? null,
                'location_area' => $context->booking->location_area ?? ($locationSnapshot['area_name'] ?? null),
                'crm_url' => $recipient->type === 'internal'
                    ? url('/admin/bookings/'.$context->booking->getKey())
                    : null,
                'starts_at' => $context->booking->startsAtUtc()->toIso8601String(),
                'ends_at' => $context->booking->endsAtUtc()->toIso8601String(),
                'local_date' => $bookingDateTime['date'],
                'local_time' => $bookingDateTime['time'],
                'timezone' => $bookingDateTime['timezone'],
                'meeting_url' => $context->booking->effectiveMeetingUrl(),
                'completed_at' => CarbonImmutable::parse((string) $context->event->occurred_at)->toIso8601String(),
            ];
            if ($includeFeedbackUrl) {
                $renderContext['feedback'] = [
                    'url' => $this->feedbackUrl(),
                ];
            }
        }

        if ($context->onboarding !== null) {
            $renderContext['onboarding'] = [
                'stage' => $context->onboarding->current_stage->value,
                'completed' => $context->onboarding->completed_at !== null,
            ];
        }

        if ($context->obligation !== null) {
            $reconciliation = app(ReconcileFinancialObligation::class)->handle(
                (int) $context->obligation->organization_id,
                (int) $context->obligation->getKey(),
            );
            $renderContext['finance'] = [
                'amount' => $context->obligation->display_amount_minor,
                'currency' => $context->obligation->display_currency->value,
                'outstanding_amount' => $reconciliation->displayOutstanding->minorUnits(),
                'outstanding_amount_display' => $reconciliation->displayOutstanding->toDecimalString(),
                'status' => $reconciliation->status->value,
            ];
        }

        if (in_array($context->event->event_name, [
            ScenarioEventType::PaymentSucceeded,
            ScenarioEventType::PaymentFailed,
            ScenarioEventType::PaymentInitiationUnavailable,
            ScenarioEventType::PaymentReconciliationRequired,
        ], true)) {
            $renderContext['payment'] = $this->paymentRenderContext($context, $recipient);
        }

        if (in_array($context->event->event_name, [
            ScenarioEventType::FulfillmentFailed,
            ScenarioEventType::FulfillmentCompleted,
        ], true)) {
            $renderContext['fulfillment'] = $this->fulfillmentRenderContext($context, $recipient);
        }

        if ($context->event->event_name === ScenarioEventType::ReferralRewardEarned) {
            $renderContext['reward'] = $this->rewardRenderContext($context);
        }

        if ($context->surveyAttempt !== null) {
            $comparison = SurveyComparison::query()
                ->where('organization_id', $context->event->organization_id)
                ->where('current_attempt_id', $context->surveyAttempt->getKey())
                ->first();
            $previous = $comparison === null
                ? null
                : SurveyAttempt::query()
                    ->where('organization_id', $context->event->organization_id)
                    ->whereKey($comparison->previous_attempt_id)
                    ->first();
            $progress = $comparison === null
                ? null
                : $this->comparisonPresentation->handle(
                    $comparison,
                    $context->surveyAttempt,
                    $previous,
                    $recipient->locale,
                );
            $renderContext['survey'] = [
                'title' => $context->surveyAttempt->surveyVersion->title,
                'version' => $context->surveyAttempt->surveyVersion->version,
                'completed_at' => $context->surveyAttempt->completed_at?->toIso8601String(),
                'portal_url' => $recipient->type === 'client' ? route('portal.surveys.index') : null,
                'has_progress' => $progress['hasData'] ?? false,
                'progress_summary' => $progress['telegramText'] ?? '',
                'crm_url' => $recipient->type === 'internal'
                    ? url('/admin/survey-attempts/'.$context->surveyAttempt->getKey())
                    : null,
            ];
        }

        if ($context->b2bSalesCall !== null) {
            $call = $context->b2bSalesCall;
            $localStart = $call->startsAtUtc()->setTimezone((string) $call->schedule_timezone);
            $joinUrl = $call->status === B2bSalesCallStatus::Scheduled
                ? ($call->meeting_mode === VideoMeetingMode::Manual
                    ? $call->manual_meeting_url
                    : ($call->provider_sync_status === VideoMeetingSyncStatus::Ready ? $call->provider_join_url : null))
                : null;
            $renderContext['sales_call'] = [
                'id' => (int) $call->getKey(),
                'local_date' => $localStart->format('d-m-Y'),
                'local_time' => $localStart->format('H:i'),
                'timezone' => (string) $call->schedule_timezone,
                'join_url' => $joinUrl,
                'specialist_name' => $call->specialist->display_name,
            ];

            if ($recipient->type === 'internal') {
                $renderContext['sales_call']['crm_url'] = url('/admin/b2b-leads/'.$call->lead_id);
            }
        }

        if (! isset($renderContext['booking']) && ! isset($renderContext['onboarding']) && ! isset($renderContext['finance']) && ! isset($renderContext['payment']) && ! isset($renderContext['fulfillment']) && ! isset($renderContext['reward']) && ! isset($renderContext['survey']) && ! isset($renderContext['sales_call']) && ! isset($renderContext['companion']) && ! isset($renderContext['payout']) && ! isset($renderContext['tracker']) && ! isset($renderContext['knowledge']) && ! $this->allowsClientlessOperationalEvent($context->event->event_name)) {
            throw (new ModelNotFoundException)->setModel(Booking::class);
        }

        return $renderContext;
    }

    public function feedbackUrl(): string
    {
        try {
            return app(ResolveTelegramMiniAppEntry::class)->launchUrl('feedback');
        } catch (LogicException|NotFoundHttpException $exception) {
            throw new FeedbackMiniAppConfigurationException($exception);
        }
    }

    private function bookingContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt): ScenarioEvaluationContext
    {
        $booking = Booking::query()
            ->where('organization_id', $event->organization_id)
            ->whereKey($this->payloadId($event, 'booking_id'))
            ->with(['client', 'service', 'specialist'])
            ->first();

        return new ScenarioEvaluationContext($event, $booking, $booking?->client, evaluationEndsAt: $evaluationEndsAt);
    }

    private function onboardingContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt): ScenarioEvaluationContext
    {
        $onboarding = ClientOnboarding::query()
            ->where('organization_id', $event->organization_id)
            ->whereKey($this->payloadId($event, 'onboarding_id'))
            ->with('client')
            ->first();

        return new ScenarioEvaluationContext(
            event: $event,
            booking: null,
            client: $onboarding?->client,
            onboarding: $onboarding,
            evaluationEndsAt: $evaluationEndsAt,
        );
    }

    private function financialContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt): ScenarioEvaluationContext
    {
        $obligation = FinancialObligation::query()
            ->where('organization_id', $event->organization_id)
            ->whereKey($this->payloadId($event, 'obligation_id'))
            ->with(['client', 'booking.service'])
            ->first();

        return new ScenarioEvaluationContext(
            event: $event,
            booking: $obligation?->booking,
            client: $obligation?->client,
            evaluationEndsAt: $evaluationEndsAt,
            obligation: $obligation,
        );
    }

    private function paymentContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt): ScenarioEvaluationContext
    {
        $gatewayEventId = $this->optionalPayloadId($event, 'gateway_event_id');
        $gatewayEvent = $gatewayEventId === null
            ? null
            : PaymentGatewayEvent::query()
                ->where('organization_id', $event->organization_id)
                ->whereKey($gatewayEventId)
                ->first();
        $transactionId = $gatewayEvent instanceof PaymentGatewayEvent
            ? $gatewayEvent->gateway_transaction_id
            : $this->optionalPayloadId($event, 'transaction_id');
        $transaction = $transactionId === null
            ? null
            : PaymentGatewayTransaction::query()
                ->where('organization_id', $event->organization_id)
                ->whereKey($transactionId)
                ->first();
        $obligationId = $this->optionalPayloadId($event, 'obligation_id') ?? $transaction?->obligation_id;
        $obligation = $obligationId === null
            ? null
            : FinancialObligation::query()
                ->where('organization_id', $event->organization_id)
                ->whereKey($obligationId)
                ->with(['client', 'booking.service', 'service', 'purchase.items.fulfillment'])
                ->first();
        $clientId = $obligation instanceof FinancialObligation
            ? $obligation->client_id
            : $this->optionalPayloadId($event, 'client_id');
        $client = $obligation?->client;
        if (! $client instanceof Client && $clientId !== null) {
            $client = Client::query()
                ->where('organization_id', $event->organization_id)
                ->whereKey($clientId)
                ->first();
        }

        return new ScenarioEvaluationContext(
            event: $event,
            booking: $obligation?->booking,
            client: $client,
            evaluationEndsAt: $evaluationEndsAt,
            obligation: $obligation,
            paymentGatewayEvent: $gatewayEvent,
        );
    }

    private function fulfillmentContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt): ScenarioEvaluationContext
    {
        $fulfillmentId = $this->optionalPayloadId($event, 'fulfillment_id');
        $fulfillment = $fulfillmentId === null
            ? null
            : PurchaseFulfillment::query()
                ->where('organization_id', $event->organization_id)
                ->whereKey($fulfillmentId)
                ->with(['item.purchase.client', 'item.purchase.obligation', 'item.purchase.items.fulfillment'])
                ->first();
        $purchase = $fulfillment?->item?->purchase;

        return new ScenarioEvaluationContext(
            event: $event,
            booking: null,
            client: $purchase?->client,
            evaluationEndsAt: $evaluationEndsAt,
            obligation: $purchase?->obligation,
            fulfillment: $fulfillment,
        );
    }

    private function rewardContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt): ScenarioEvaluationContext
    {
        $rewardId = $this->optionalPayloadId($event, 'reward_entry_id');
        $reward = $rewardId === null
            ? null
            : ReferralRewardLedgerEntry::query()
                ->where('organization_id', $event->organization_id)
                ->whereKey($rewardId)
                ->with('beneficiary')
                ->first();

        return new ScenarioEvaluationContext(
            event: $event,
            booking: null,
            client: $reward?->beneficiary,
            evaluationEndsAt: $evaluationEndsAt,
            reward: $reward,
        );
    }

    /** @return array<string, scalar|null> */
    private function paymentRenderContext(ScenarioEvaluationContext $context, ScenarioRecipient $recipient): array
    {
        $event = $context->event;
        $currency = $context->obligation?->payment_currency->value ?? (string) ($event->payload['currency'] ?? '');
        $amountMinor = $event->payload['amount_minor'] ?? $context->obligation?->payment_amount_minor;
        $productName = $this->obligationProductName($context->obligation, $recipient);
        if ($productName === $this->defaultProductName($recipient)) {
            $productName = $this->sellableProductName($event, $recipient);
        }
        $isEnglishClient = $this->isEnglishClient($recipient);
        $message = match ($event->event_name) {
            ScenarioEventType::PaymentSucceeded => $isEnglishClient ? 'Payment received.' : 'Оплата получена.',
            ScenarioEventType::PaymentFailed => $isEnglishClient
                ? "We couldn't complete the payment. Please try again. If the money has already been charged, don't pay again — we'll check the payment."
                : 'Оплату завершить не удалось. Попробуйте ещё раз. Если деньги уже списались, не оплачивайте повторно — мы проверим платёж.',
            ScenarioEventType::PaymentInitiationUnavailable => (string) ($event->payload['message'] ?? 'Онлайн-оплата сейчас временно недоступна. Попробуйте позже.'),
            default => 'Платёж требует проверки.',
        };

        return [
            'amount' => $this->formatAmount($amountMinor, $currency),
            'currency' => $currency,
            'product_name' => $productName,
            'status_label' => match ($event->event_name) {
                ScenarioEventType::PaymentSucceeded => $isEnglishClient ? 'Paid' : 'Оплачено',
                ScenarioEventType::PaymentFailed => $isEnglishClient ? 'Payment failed' : 'Оплата не прошла',
                ScenarioEventType::PaymentInitiationUnavailable => $isEnglishClient ? 'Online payment is unavailable' : 'Онлайн-оплата недоступна',
                default => $isEnglishClient ? 'Needs review' : 'Требует сверки',
            },
            'reason' => (string) ($event->payload['reason'] ?? 'Платёж требует проверки.'),
            'client_label' => $context->client instanceof Client
                ? $this->clientDisplayName($context->client)
                : 'Клиент не сопоставлен',
            'message' => $message,
            'survey_url' => $recipient->type === 'client'
                && $event->event_name === ScenarioEventType::PaymentSucceeded
                && $this->paymentEligibility->handle($context)
                && $this->hasAvailableSurvey($event->organization_id)
                ? route('portal.surveys.index')
                : null,
            'crm_url' => $recipient->type === 'internal'
                ? ($event->event_name === ScenarioEventType::PaymentInitiationUnavailable
                    ? url('/admin/finance-configuration')
                    : url('/admin/payment-gateway-reconciliation'))
                : null,
        ];
    }

    /** @return array<string, scalar|null> */
    private function fulfillmentRenderContext(ScenarioEvaluationContext $context, ScenarioRecipient $recipient): array
    {
        $fulfillment = $context->fulfillment;
        $purchase = $fulfillment?->item?->purchase;
        $failed = $context->event->event_name === ScenarioEventType::FulfillmentFailed;
        $isEnglishClient = $this->isEnglishClient($recipient);

        return [
            'product_name' => $this->purchaseItemProductName($fulfillment?->item, $recipient),
            'status_label' => $failed
                ? ($isEnglishClient ? 'Access issue' : 'Ошибка выдачи')
                : ($isEnglishClient ? 'Access ready' : 'Доступ выдан'),
            'reason' => $failed
                ? $this->fulfillmentReason((string) ($context->event->payload['reason'] ?? ''))
                : '',
            'message' => $failed
                ? ($isEnglishClient
                    ? "Payment received. Your access is still being prepared. You don't need to pay again."
                    : 'Оплата получена. Доступ пока готовится. Повторно оплачивать не нужно.')
                : ($isEnglishClient ? 'Your access is ready.' : 'Доступ готов.'),
            'crm_url' => $recipient->type === 'internal'
                ? ($purchase?->obligation?->getKey() === null
                    ? url('/admin/financial-obligations')
                    : url('/admin/financial-obligations/'.$purchase->obligation->getKey()))
                : null,
        ];
    }

    /** @return array<string, scalar|null> */
    private function rewardRenderContext(ScenarioEvaluationContext $context): array
    {
        $reward = $context->reward;
        $currency = $reward instanceof ReferralRewardLedgerEntry
            ? $reward->currency->value
            : (string) ($context->event->payload['currency'] ?? '');
        $amountMinor = $reward instanceof ReferralRewardLedgerEntry
            ? $reward->amount_minor
            : ($context->event->payload['amount_minor'] ?? null);

        return [
            'amount' => $this->formatAmount($amountMinor, $currency),
            'currency' => $currency,
            'portal_url' => url('/portal/referrals'),
        ];
    }

    private function obligationProductName(?FinancialObligation $obligation, ScenarioRecipient $recipient): string
    {
        $booking = $obligation?->getRelationValue('booking');
        $service = $booking instanceof Booking ? $booking->service : $obligation?->service;
        if ($service !== null && trim((string) $service->name) !== '') {
            return trim((string) $service->name);
        }

        return $this->purchaseItemProductName($obligation?->purchase?->items?->first(), $recipient);
    }

    private function sellableProductName(ScenarioEvent $event, ScenarioRecipient $recipient): string
    {
        $sellableType = $event->payload['sellable_type'] ?? null;
        $sellableId = $event->payload['sellable_id'] ?? null;
        if (! is_string($sellableType) || ! is_numeric($sellableId)) {
            return $this->defaultProductName($recipient);
        }

        $id = (int) $sellableId;
        if ($sellableType === Service::class) {
            $service = Service::query()
                ->where('organization_id', $event->organization_id)
                ->whereKey($id)
                ->first();

            return $service instanceof Service && trim((string) $service->name) !== ''
                ? trim((string) $service->name)
                : $this->defaultProductName($recipient);
        }

        if ($sellableType === TrackerPlanVersion::class) {
            $version = TrackerPlanVersion::query()
                ->where('organization_id', $event->organization_id)
                ->whereKey($id)
                ->with('plan')
                ->first();

            return $version instanceof TrackerPlanVersion && $version->plan !== null && trim((string) $version->plan->name) !== ''
                ? trim((string) $version->plan->name)
                : $this->defaultProductName($recipient);
        }

        return $this->defaultProductName($recipient);
    }

    private function purchaseItemProductName(?PurchaseItem $item, ScenarioRecipient $recipient): string
    {
        $snapshot = $item?->getRawOriginal('product_snapshot');
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }
        if (is_array($snapshot)) {
            foreach (['name', 'plan_name', 'title'] as $key) {
                if (is_string($snapshot[$key] ?? null) && trim($snapshot[$key]) !== '') {
                    return trim($snapshot[$key]);
                }
            }
        }

        return $this->defaultProductName($recipient);
    }

    private function defaultProductName(ScenarioRecipient $recipient): string
    {
        return $this->isEnglishClient($recipient) ? 'Purchase' : 'Покупка';
    }

    private function payoutStatusLabel(ReferralPayoutRequestStatus $status, ScenarioRecipient $recipient): string
    {
        if (! $this->isEnglishClient($recipient)) {
            return $status->label();
        }

        return match ($status) {
            ReferralPayoutRequestStatus::Requested => 'requested',
            ReferralPayoutRequestStatus::Approved => 'approved',
            ReferralPayoutRequestStatus::Paid => 'marked as paid',
            ReferralPayoutRequestStatus::Rejected => 'rejected',
            ReferralPayoutRequestStatus::Cancelled => 'cancelled',
        };
    }

    private function isEnglishClient(ScenarioRecipient $recipient): bool
    {
        return $recipient->type === 'client' && $recipient->locale === 'en';
    }

    private function fulfillmentReason(string $reason): string
    {
        return match ($reason) {
            'tracker_plan_version_unavailable' => 'Не удалось найти сохранённую версию тарифа трекера.',
            'provider_failed' => 'Не удалось выдать доступ автоматически.',
            default => 'Не удалось выдать доступ автоматически.',
        };
    }

    private function formatAmount(mixed $amountMinor, mixed $currency): string
    {
        if (! is_numeric($amountMinor)) {
            return '—';
        }
        $code = CurrencyCode::tryFrom((string) $currency);
        if (! $code instanceof CurrencyCode) {
            return '—';
        }

        return Money::ofMinor((int) $amountMinor, $code)->toDecimalString().' '.$code->value;
    }

    private function surveyContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt): ScenarioEvaluationContext
    {
        $attempt = SurveyAttempt::query()
            ->where('organization_id', $event->organization_id)
            ->whereKey($this->payloadId($event, 'attempt_id'))
            ->with(['client', 'surveyVersion'])
            ->first();
        if (! $attempt instanceof SurveyAttempt) {
            $attempt = null;
        }

        return new ScenarioEvaluationContext(
            event: $event,
            booking: null,
            client: $attempt?->client,
            evaluationEndsAt: $evaluationEndsAt,
            surveyAttempt: $attempt,
        );
    }

    private function hasAvailableSurvey(int $organizationId): bool
    {
        return SurveyDefinition::query()
            ->where('organization_id', $organizationId)
            ->where('is_available', true)
            ->whereHas('activeVersion', fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->where('status', SurveyVersionStatus::Published->value))
            ->exists();
    }

    private function b2bLeadContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt): ScenarioEvaluationContext
    {
        $lead = B2bLead::query()
            ->where('organization_id', $event->organization_id)
            ->whereKey($this->payloadId($event, 'lead_id'))
            ->with(['client', 'salesCall.specialist'])
            ->first();

        return new ScenarioEvaluationContext(
            event: $event,
            booking: null,
            client: $lead?->client,
            evaluationEndsAt: $evaluationEndsAt,
            b2bLead: $lead,
            b2bSalesCall: $lead?->salesCall,
        );
    }

    private function b2bSalesCallContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt): ScenarioEvaluationContext
    {
        $query = B2bSalesCall::query()
            ->where('organization_id', $event->organization_id)
            ->whereKey($this->payloadId($event, 'sales_call_id'))
            ->with(['client', 'specialist', 'lead']);
        if ($event->event_name === ScenarioEventType::B2bSalesCallReady && DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $call = $query->first();

        return new ScenarioEvaluationContext(
            event: $event,
            booking: null,
            client: $call?->client,
            evaluationEndsAt: $evaluationEndsAt,
            b2bLead: $call?->lead,
            b2bSalesCall: $call,
        );
    }

    private function companionContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt): ScenarioEvaluationContext
    {
        $client = Client::query()
            ->where('organization_id', $event->organization_id)
            ->whereKey($this->payloadId($event, 'client_id'))
            ->first();

        return new ScenarioEvaluationContext(
            event: $event,
            booking: null,
            client: $client,
            evaluationEndsAt: $evaluationEndsAt,
        );
    }

    private function genericClientContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt): ScenarioEvaluationContext
    {
        $clientId = $event->payload['client_id'] ?? null;
        $client = is_int($clientId) || (is_string($clientId) && ctype_digit($clientId))
            ? Client::query()->where('organization_id', $event->organization_id)->whereKey((int) $clientId)->first()
            : null;

        return new ScenarioEvaluationContext(
            event: $event,
            booking: null,
            client: $client,
            evaluationEndsAt: $evaluationEndsAt,
        );
    }

    private function allowsClientlessOperationalEvent(ScenarioEventType $eventType): bool
    {
        return in_array($eventType, [
            ScenarioEventType::BroadcastDeliveryFailed,
            ScenarioEventType::ClientFeedbackSubmitted,
            ScenarioEventType::AiEvaluationFailed,
            ScenarioEventType::KnowledgeIngestionFailed,
            ScenarioEventType::ReferralLinkVisited,
            ScenarioEventType::PaymentProviderEventPrepared,
            ScenarioEventType::PaymentSucceeded,
            ScenarioEventType::PaymentFailed,
            ScenarioEventType::PaymentInitiationUnavailable,
            ScenarioEventType::PaymentReconciliationRequired,
            ScenarioEventType::FulfillmentFailed,
            ScenarioEventType::FulfillmentCompleted,
            ScenarioEventType::ReferralRewardEarned,
        ], true);
    }

    public function financeDebtIsCurrent(ScenarioEvaluationContext $context): bool
    {
        if ($context->obligation === null) {
            return false;
        }

        return ! app(ReconcileFinancialObligation::class)
            ->handle((int) $context->obligation->organization_id, (int) $context->obligation->getKey())
            ->isSettled();
    }

    /** @param array<string, mixed> $snapshot */
    private function locationLabel(Booking $booking, array $snapshot, string $locale, bool $internal): string
    {
        $name = is_string($snapshot['name'] ?? null) ? trim($snapshot['name']) : '';
        $address = is_string($snapshot['address'] ?? null) ? trim($snapshot['address']) : trim((string) $booking->location);
        $area = is_string($snapshot['area_name'] ?? null)
            ? trim($snapshot['area_name'])
            : trim((string) $booking->location_area);

        $homeVisitLines = [];
        if ($area !== '') {
            $homeVisitLines[] = $area;
        }
        if ($address !== '') {
            $homeVisitLines[] = $internal
                ? ($this->isRussian($locale) ? 'Адрес клиента: '.$address : 'Client address: '.$address)
                : ($this->isRussian($locale) ? 'Адрес: '.$address : 'Address: '.$address);
        }

        return match ($booking->visit_format) {
            VisitFormat::Office => implode("\n", array_values(array_unique(array_filter([$name, $address]), SORT_STRING)))
                ?: '',
            VisitFormat::HomeVisit => implode("\n", $homeVisitLines),
            VisitFormat::Online => '',
        };
    }

    /** @param array<string, mixed> $snapshot */
    private function visitDetails(Booking $booking, array $snapshot, string $locale, bool $internal): string
    {
        $format = $this->visitFormatLabel($booking->visit_format->value, $locale);
        $location = $this->locationLabel($booking, $snapshot, $locale, $internal);

        return $location === '' ? $format : $format."\n".$location;
    }

    private function visitFormatLabel(string $format, string $locale): string
    {
        $label = match ($format) {
            VisitFormat::Office->value => $this->isRussian($locale) ? 'В клинике' : 'At the clinic',
            VisitFormat::HomeVisit->value => $this->isRussian($locale) ? 'Выезд на дом' : 'Home visit',
            VisitFormat::Online->value => $this->isRussian($locale) ? 'Онлайн' : 'Online',
            default => $format,
        };

        return $label;
    }

    private function isRussian(string $locale): bool
    {
        return str_starts_with(strtolower($locale), 'ru');
    }

    private function clientTelegramContact(Client $client): string
    {
        $identity = ClientChannelIdentity::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->where('channel', 'telegram')
            ->where('verification_status', ChannelIdentityStatus::Verified->value)
            ->first();

        if ($identity === null) {
            return 'не указан';
        }

        $username = trim((string) $identity->external_username);
        $externalId = trim((string) $identity->external_id);

        if ($username !== '' && $externalId !== '') {
            return '@'.ltrim($username, '@').' (ID: '.$externalId.')';
        }

        if ($username !== '') {
            return '@'.ltrim($username, '@');
        }

        return $externalId !== '' ? 'ID: '.$externalId : 'не указан';
    }

    private function clientTelegramProfileUrl(Client $client): ?string
    {
        $identity = ClientChannelIdentity::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->where('channel', 'telegram')
            ->where('verification_status', ChannelIdentityStatus::Verified->value)
            ->first();
        $externalId = trim((string) $identity?->external_id);

        return preg_match('/^[1-9][0-9]{0,19}$/', $externalId) === 1
            ? 'tg://user?id='.$externalId
            : null;
    }

    private function clientDisplayName(Client $client): string
    {
        $name = trim((string) $client->full_name);

        return $name !== '' ? $name : '#'.$client->getKey();
    }

    private function payloadId(ScenarioEvent $event, string $key): int
    {
        $value = $event->payload[$key] ?? null;

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new InvalidArgumentException('The scenario event payload identifier is invalid.');
    }

    private function optionalPayloadId(ScenarioEvent $event, string $key): ?int
    {
        $value = $event->payload[$key] ?? null;

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
