<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\Channels\Application\ResolveTelegramMiniAppEntry;
use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Exceptions\FeedbackMiniAppConfigurationException;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRecipient;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ScenarioContextFactory
{
    public function evaluationContext(ScenarioEvent $event, ?CarbonImmutable $evaluationEndsAt = null): ScenarioEvaluationContext
    {
        return match ($event->event_name) {
            ScenarioEventType::BookingCreated => $this->bookingContext($event, $evaluationEndsAt),
            ScenarioEventType::BookingConfirmed => $this->bookingContext($event, $evaluationEndsAt),
            ScenarioEventType::BookingRescheduled => $this->bookingContext($event, $evaluationEndsAt),
            ScenarioEventType::BookingCancelled => $this->bookingContext($event, $evaluationEndsAt),
            ScenarioEventType::BookingCompleted => $this->bookingContext($event, $evaluationEndsAt),
            ScenarioEventType::OnboardingStarted => $this->onboardingContext($event, $evaluationEndsAt),
            ScenarioEventType::FinancialObligationCreated => $this->financialContext($event, $evaluationEndsAt),
            ScenarioEventType::SurveyCompleted, ScenarioEventType::TestStagnationDetected => $this->surveyContext($event, $evaluationEndsAt),
            ScenarioEventType::B2bLeadSubmitted => $this->b2bLeadContext($event, $evaluationEndsAt),
            ScenarioEventType::B2bSalesCallReady => $this->b2bSalesCallContext($event, $evaluationEndsAt),
        };
    }

    /** @return array<string, mixed> */
    public function renderContext(
        ScenarioEvaluationContext $context,
        ScenarioRecipient $recipient,
        bool $includeFeedbackUrl = false,
    ): array {
        if ($context->client === null) {
            throw (new ModelNotFoundException)->setModel(Client::class);
        }

        $renderContext = [
            'client' => [
                'full_name' => $this->clientDisplayName($context->client),
                'language' => strtolower((string) ($context->client->language ?? 'en')),
            ],
            'recipient_locale' => $recipient->locale,
        ];

        if ($recipient->type === 'internal') {
            $renderContext['client']['telegram_contact'] = $this->clientTelegramContact($context->client);
            $renderContext['client']['telegram_profile_url'] = $this->clientTelegramProfileUrl($context->client);
        }

        if ($context->booking !== null) {
            $renderContext['booking'] = [
                'id' => (int) $context->booking->getKey(),
                'event_version' => (int) $context->booking->event_version,
                'status' => $context->booking->status->value,
                'visit_format' => $context->booking->visit_format->value,
                'service_name' => $context->booking->service->name,
                'specialist_name' => $context->booking->specialist->display_name,
                'starts_at' => $context->booking->startsAtUtc()->toIso8601String(),
                'ends_at' => $context->booking->endsAtUtc()->toIso8601String(),
                'local_date' => $context->booking->startsAtUtc()->setTimezone($this->bookingTimezone($context->booking))->format('d-m-Y'),
                'local_time' => $context->booking->startsAtUtc()->setTimezone($this->bookingTimezone($context->booking))->format('H:i'),
                'timezone' => $this->bookingTimezone($context->booking),
                'meeting_url' => $context->booking->visit_format->value === 'online' ? $context->booking->meeting_url : null,
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
                'status' => $reconciliation->status->value,
            ];
        }

        if ($context->surveyAttempt !== null) {
            $renderContext['survey'] = [
                'title' => $context->surveyAttempt->surveyVersion->title,
                'version' => $context->surveyAttempt->surveyVersion->version,
                'completed_at' => $context->surveyAttempt->completed_at?->toIso8601String(),
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

        if (! isset($renderContext['booking']) && ! isset($renderContext['onboarding']) && ! isset($renderContext['finance']) && ! isset($renderContext['survey']) && ! isset($renderContext['sales_call'])) {
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

    public function financeDebtIsCurrent(ScenarioEvaluationContext $context): bool
    {
        if ($context->obligation === null) {
            return false;
        }

        return ! app(ReconcileFinancialObligation::class)
            ->handle((int) $context->obligation->organization_id, (int) $context->obligation->getKey())
            ->isSettled();
    }

    private function bookingTimezone(Booking $booking): string
    {
        return (string) ($booking->client_timezone ?: $booking->client->timezone ?: $booking->schedule_timezone);
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
}
