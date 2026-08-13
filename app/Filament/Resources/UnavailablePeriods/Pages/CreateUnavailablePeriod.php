<?php

namespace App\Filament\Resources\UnavailablePeriods\Pages;

use App\Filament\Resources\UnavailablePeriods\UnavailablePeriodResource;
use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\CreateUnavailablePeriod as CreateUnavailablePeriodAction;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateUnavailablePeriod extends CreateRecord
{
    protected static string $resource = UnavailablePeriodResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $context = app(OrganizationContext::class);
        $specialist = Specialist::query()
            ->where('organization_id', $context->id())
            ->findOrFail((int) $data['specialist_id']);
        $timezone = $context->organization()->defaultTimezone();

        try {
            return app(CreateUnavailablePeriodAction::class)->handle(
                actor: $actor,
                specialist: $specialist,
                startsAt: $this->toDateTime($data['starts_at'], $timezone),
                endsAt: $this->toDateTime($data['ends_at'], $timezone),
                reason: $data['reason'] ?? null,
                acknowledgeImpact: (bool) ($data['acknowledge_impact'] ?? false),
                acknowledgedImpactDigest: isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
            );
        } catch (ValidationException $exception) {
            $this->form->fill(ScheduleImpactPreview::mergeValidationPreview($data, $exception));

            throw $exception;
        }
    }

    private function toDateTime(mixed $value, string $timezone): DateTimeInterface
    {
        return $value instanceof DateTimeInterface
            ? $value
            : CarbonImmutable::parse((string) $value, $timezone);
    }
}
