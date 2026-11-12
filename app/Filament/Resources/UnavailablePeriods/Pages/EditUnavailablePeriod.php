<?php

namespace App\Filament\Resources\UnavailablePeriods\Pages;

use App\Filament\Resources\UnavailablePeriods\UnavailablePeriodResource;
use App\Filament\Support\InteractsWithFormValidationFeedback;
use App\Filament\Support\LocalizedEditRecord;
use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\UpdateUnavailablePeriod;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EditUnavailablePeriod extends LocalizedEditRecord
{
    use InteractsWithFormValidationFeedback;

    protected static string $resource = UnavailablePeriodResource::class;

    protected static ?string $title = 'Изменить период недоступности';

    public function getFormContentComponent(): Component
    {
        return parent::getFormContentComponent()->extraAttributes(['novalidate' => true]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        abort_unless($this->record instanceof UnavailablePeriod, 404);
        $timezone = app(OrganizationContext::class)->organization()->defaultTimezone();

        return [
            ...$data,
            'specialist_id' => (int) $this->record->getAttribute('specialist_id'),
            'starts_at' => CarbonImmutable::parse((string) $this->record->getAttribute('starts_at'))->setTimezone($timezone),
            'ends_at' => CarbonImmutable::parse((string) $this->record->getAttribute('ends_at'))->setTimezone($timezone),
            'reason' => $this->record->getAttribute('reason'),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof UnavailablePeriod, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $context = app(OrganizationContext::class);
        $specialist = Specialist::query()
            ->where('organization_id', $context->id())
            ->findOrFail((int) $data['specialist_id']);
        $timezone = $context->organization()->defaultTimezone();

        try {
            return app(UpdateUnavailablePeriod::class)->handle(
                actor: $actor,
                period: $record,
                specialist: $specialist,
                startsAt: $this->toDateTime($data['starts_at'] ?? null, $timezone),
                endsAt: $this->toDateTime($data['ends_at'] ?? null, $timezone),
                reason: $data['reason'] ?? null,
                acknowledgeImpact: (bool) ($data['acknowledge_impact'] ?? false),
                acknowledgedImpactDigest: isset($data['impact_digest']) ? (string) $data['impact_digest'] : null,
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

    private function toDateTime(mixed $value, string $timezone): DateTimeInterface
    {
        return $value instanceof DateTimeInterface
            ? $value
            : CarbonImmutable::parse((string) $value, $timezone);
    }
}
