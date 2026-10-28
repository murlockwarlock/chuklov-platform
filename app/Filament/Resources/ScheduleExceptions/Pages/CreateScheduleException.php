<?php

namespace App\Filament\Resources\ScheduleExceptions\Pages;

use App\Filament\Resources\ScheduleExceptions\ScheduleExceptionResource;
use App\Filament\Support\LocalizedCreateRecord;
use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\CreateScheduleException as CreateScheduleExceptionAction;
use App\Modules\Scheduling\Application\CreateUnavailablePeriod as CreateUnavailablePeriodAction;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateScheduleException extends LocalizedCreateRecord
{
    protected static string $resource = ScheduleExceptionResource::class;

    protected static ?string $title = 'Добавить исключение расписания';

    public function getFormContentComponent(): Component
    {
        return parent::getFormContentComponent()->extraAttributes(['novalidate' => true]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $specialist = Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->findOrFail((int) $data['specialist_id']);

        try {
            if (($data['change_type'] ?? null) === 'unavailable') {
                $timezone = app(OrganizationContext::class)->organization()->defaultTimezone();

                return app(CreateUnavailablePeriodAction::class)->handle(
                    actor: $actor,
                    specialist: $specialist,
                    startsAt: $this->toDateTime($data['starts_at'] ?? null, $timezone),
                    endsAt: $this->toDateTime($data['ends_at'] ?? null, $timezone),
                    reason: $data['reason'] ?? null,
                    acknowledgeImpact: (bool) ($data['acknowledge_impact'] ?? false),
                    acknowledgedImpactDigest: isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
                );
            }

            return app(CreateScheduleExceptionAction::class)->handle(
                $actor,
                $specialist,
                $data,
                (bool) ($data['acknowledge_impact'] ?? false),
                isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
            );
        } catch (ValidationException $exception) {
            $this->form->fill(ScheduleImpactPreview::mergeValidationPreview($data, $exception));
            $errors = $exception->errors();

            foreach (['exception_date', 'exception_type', 'start_time', 'end_time', 'starts_at', 'ends_at', 'reason'] as $field) {
                if (isset($errors[$field])) {
                    $errors['data.'.$field] = $errors[$field];
                    unset($errors[$field]);
                }
            }

            throw ValidationException::withMessages($errors);
        }
    }

    private function toDateTime(mixed $value, string $timezone): DateTimeInterface
    {
        return $value instanceof DateTimeInterface
            ? $value
            : CarbonImmutable::parse((string) $value, $timezone);
    }
}
