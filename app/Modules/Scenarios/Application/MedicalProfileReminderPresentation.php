<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\AppointmentReminder;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioConditionSet;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Scheduling\Application\BookingPatientAccounts;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class MedicalProfileReminderPresentation
{
    public function __construct(private BookingNeedsMedicalProfile $medicalProfile, private ConditionEvaluatorRegistry $conditions, private BookingPatientAccounts $accounts) {}

    /** @return array{template_version_id: int, offset_label: string}|null */
    public function snapshot(AppointmentReminder $reminder, string $locale): ?array
    {
        if ($reminder->recipient_type !== 'client' || ! $reminder->medical_profile_prompt) {
            return null;
        }

        $query = NotificationTemplateVersion::query()
            ->where('organization_id', $reminder->organization_id)
            ->where('status', NotificationTemplateStatus::Published->value)
            ->whereHas('template', fn ($template) => $template
                ->where('template_key', 'medical-profile-reminder')
                ->where('purpose', 'service')->where('is_active', true))
            ->with('template')->latest('id');
        $template = (clone $query)->whereHas('template', fn ($template) => $template->where('locale', $locale))->first()
            ?? $query->whereHas('template', fn ($template) => $template->where('locale', 'ru'))->first();
        if ($template === null) {
            throw new ModelNotFoundException;
        }

        $seconds = $reminder->offset_unit->toSeconds($reminder->offset_value);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds, 60);

        return [
            'template_version_id' => (int) $template->getKey(),
            'offset_label' => $seconds % 3600 === 0
                ? ($locale === 'ru' ? $this->hoursLabel($hours) : $hours.($hours === 1 ? ' hour' : ' hours'))
                : ($locale === 'ru' ? $minutes.' минут' : $minutes.' minutes'),
        ];
    }

    public function applies(ScenarioAction $action, Booking $booking): bool
    {
        return $this->needsPrompt($action, $booking)
            && $action->appointmentReminder?->medical_profile_prompt
            && is_array($action->render_context['medical_profile_reminder'] ?? null);
    }

    public function needsPrompt(ScenarioAction $action, ?Booking $booking): bool
    {
        return $booking !== null && $action->recipient_type === 'client'
            && $this->accounts->canCompleteMedicalProfile($booking, (int) $action->client_id)
            && (int) $action->organization_id === (int) $booking->organization_id
            && $this->medicalProfile->needsCompletion($booking);
    }

    public function prefersMedicalCreation(ScenarioAction $action, ScenarioEvaluationContext $context): bool
    {
        $snapshot = $action->render_context['medical_profile_after_booking'] ?? null;
        if (! is_array($snapshot) || ! is_int($snapshot['rule_id'] ?? null) || ! is_int($snapshot['template_version_id'] ?? null)
            || ! is_array($snapshot['conditions'] ?? null)) {
            return false;
        }

        return $action->templateVersion?->template?->template_key === 'booking-created'
            && $this->needsPrompt($action, $context->booking)
            && $this->conditions->matches(ScenarioConditionSet::from($snapshot['conditions']), $context)
            && ScenarioRule::query()->where('organization_id', $action->organization_id)
                ->whereKey($snapshot['rule_id'])->where('is_enabled', true)->exists()
            && NotificationTemplateVersion::query()->where('organization_id', $action->organization_id)
                ->whereKey($snapshot['template_version_id'])->where('status', NotificationTemplateStatus::Published->value)
                ->whereHas('template', fn ($template) => $template->where('is_active', true)
                    ->where('template_key', 'medical-profile-after-booking')->where('purpose', 'transactional'))->exists();
    }

    public function creationIsCurrent(ScenarioAction $action, ScenarioEvaluationContext $context): bool
    {
        $key = $action->templateVersion?->template?->template_key;
        if ($action->trigger_event !== ScenarioEventType::BookingCreated || $action->recipient_type !== 'client'
            || (! isset($action->render_context['medical_profile_after_booking']) && $key !== 'medical-profile-after-booking')) {
            return true;
        }
        $booking = $context->booking;
        $payload = $context->event->getAttribute('payload');
        if ($booking === null || ! is_array($payload)
            || (int) $booking->organization_id !== (int) $action->organization_id
            || ! $this->accounts->includes($booking, (int) $action->client_id)
            || ! in_array($booking->status, [BookingStatus::Requested, BookingStatus::PendingReview, BookingStatus::Confirmed], true)
            || $booking->startsAtUtc()->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
            || ($payload['starts_at'] ?? null) !== $booking->startsAtUtc()->toIso8601String()
            || ($payload['ends_at'] ?? null) !== $booking->endsAtUtc()->toIso8601String()) {
            return false;
        }

        return $key !== 'booking-created' || $booking->status !== BookingStatus::Confirmed || $this->prefersMedicalCreation($action, $context);
    }

    public function bookingNeedsCompletion(Booking $booking): bool
    {
        return $this->medicalProfile->needsCompletion($booking);
    }

    public function template(ScenarioAction $action): NotificationTemplateVersion
    {
        return $this->pinnedTemplate($action, 'medical_profile_reminder', 'medical-profile-reminder');
    }

    public function creationTemplate(ScenarioAction $action): NotificationTemplateVersion
    {
        return $this->pinnedTemplate($action, 'medical_profile_after_booking', 'medical-profile-after-booking');
    }

    private function pinnedTemplate(ScenarioAction $action, string $snapshot, string $key): NotificationTemplateVersion
    {
        $id = $action->render_context[$snapshot]['template_version_id'] ?? null;
        if (! is_int($id) || $id < 1) {
            throw new ModelNotFoundException;
        }

        return NotificationTemplateVersion::query()
            ->where('organization_id', $action->organization_id)->whereKey($id)
            ->where('status', NotificationTemplateStatus::Published->value)
            ->whereHas('template', fn ($template) => $template->where('template_key', $key))
            ->with('template')->firstOrFail();
    }

    private function hoursLabel(int $hours): string
    {
        $suffix = $hours % 100 >= 11 && $hours % 100 <= 14 ? 'часов' : match ($hours % 10) {
            1 => 'час',
            2, 3, 4 => 'часа',
            default => 'часов',
        };

        return $hours.' '.$suffix;
    }
}
