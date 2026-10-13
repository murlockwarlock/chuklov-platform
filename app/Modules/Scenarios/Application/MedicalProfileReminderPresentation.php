<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Models\AppointmentReminder;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scheduling\Domain\Models\Booking;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class MedicalProfileReminderPresentation
{
    public function __construct(private BookingNeedsMedicalProfile $medicalProfile) {}

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
            && (int) $action->client_id === (int) $booking->client_id
            && (int) $action->organization_id === (int) $booking->organization_id
            && $this->medicalProfile->needsCompletion($booking);
    }

    public function bookingNeedsCompletion(Booking $booking): bool
    {
        return $this->medicalProfile->needsCompletion($booking);
    }

    public function template(ScenarioAction $action): NotificationTemplateVersion
    {
        $id = $action->render_context['medical_profile_reminder']['template_version_id'] ?? null;
        if (! is_int($id) || $id < 1) {
            throw new ModelNotFoundException;
        }

        return NotificationTemplateVersion::query()
            ->where('organization_id', $action->organization_id)->whereKey($id)
            ->where('status', NotificationTemplateStatus::Published->value)
            ->whereHas('template', fn ($template) => $template->where('template_key', 'medical-profile-reminder'))
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
