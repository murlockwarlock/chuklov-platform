<?php

namespace App\Filament\Resources\ScheduleExceptions\Pages;

use App\Filament\Resources\ScheduleExceptions\ScheduleExceptionResource;
use App\Filament\Support\InteractsWithFormValidationFeedback;
use App\Filament\Support\LocalizedEditRecord;
use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\UpdateScheduleException;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EditScheduleException extends LocalizedEditRecord
{
    use InteractsWithFormValidationFeedback;

    protected static string $resource = ScheduleExceptionResource::class;

    protected static ?string $title = 'Изменить исключение расписания';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        abort_unless($this->record instanceof ScheduleException, 404);
        abort_if(($this->record->getAttribute('change_kind') ?? 'exception') === 'unavailable', 404);

        $type = $this->record->getAttribute('exception_type');

        return [
            ...$data,
            'change_type' => 'working_intervals',
            'specialist_id' => (int) $this->record->getAttribute('specialist_id'),
            'exception_date' => $this->record->getAttribute('exception_date'),
            'exception_type' => $type instanceof ScheduleExceptionType ? $type->value : (string) $type,
            'start_time' => $this->record->getAttribute('start_time'),
            'end_time' => $this->record->getAttribute('end_time'),
            'reason' => $this->record->getAttribute('reason'),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof ScheduleException, 404);
        abort_if(($record->getAttribute('change_kind') ?? 'exception') === 'unavailable', 404);

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $specialist = Specialist::query()
            ->where('organization_id', app(OrganizationContext::class)->id())
            ->findOrFail((int) $data['specialist_id']);

        try {
            return app(UpdateScheduleException::class)->handle(
                actor: $actor,
                exception: $record,
                attributes: [
                    'exception_date' => $data['exception_date'] ?? null,
                    'exception_type' => $data['exception_type'] ?? null,
                    'start_time' => $data['start_time'] ?? null,
                    'end_time' => $data['end_time'] ?? null,
                    'reason' => $data['reason'] ?? null,
                ],
                acknowledgeImpact: (bool) ($data['acknowledge_impact'] ?? false),
                acknowledgedImpactDigest: isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
                targetSpecialist: $specialist,
            );
        } catch (ValidationException $exception) {
            throw $this->formValidationFailure(
                $exception,
                ScheduleImpactPreview::mergeValidationPreview($data, $exception),
                aliases: ['schedule_impact' => 'acknowledge_impact'],
                preserve: ['schedule_impact'],
            );
        } catch (Throwable $exception) {
            throw $this->unexpectedFormFailure($exception, $data);
        }
    }
}
