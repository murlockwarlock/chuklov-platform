<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\ValueObjects\ScheduleExceptionDefinition;
use App\Modules\Scheduling\Domain\ValueObjects\WallClockInterval;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Models\Specialist;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateScheduleException
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly ScheduleMutationImpactCalculator $impactCalculator,
        private readonly EnsureScheduleMutationImpactAcknowledged $impactAcknowledgement,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(
        User $actor,
        Specialist $specialist,
        array $attributes,
        bool $acknowledgeImpact = false,
        ?string $acknowledgedImpactDigest = null,
    ): ScheduleException {
        $organization = $this->context->organization();

        if ((int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);
        $attributes = $this->normalizeAttributes($attributes);
        $attributes = Validator::make($attributes, [
            'exception_date' => ['required', 'date_format:Y-m-d'],
            'exception_type' => ['required', 'in:day_off,custom_window'],
            'start_time' => ['nullable', 'required_if:exception_type,custom_window', 'prohibited_if:exception_type,day_off', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/'],
            'end_time' => ['nullable', 'required_if:exception_type,custom_window', 'prohibited_if:exception_type,day_off', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'exception_date.required' => __('Укажите дату изменения расписания.'),
            'exception_date.date_format' => __('Укажите корректную дату изменения расписания.'),
            'exception_type.required' => __('Выберите тип изменения.'),
            'exception_type.in' => __('Выберите тип изменения.'),
            'start_time.required_if' => __('Укажите время начала.'),
            'start_time.prohibited_if' => __('Для выходного дня не указывайте время.'),
            'start_time.regex' => __('Укажите время начала в формате ЧЧ:ММ.'),
            'end_time.required_if' => __('Укажите время окончания.'),
            'end_time.prohibited_if' => __('Для выходного дня не указывайте время.'),
            'end_time.regex' => __('Укажите время окончания в формате ЧЧ:ММ.'),
            'reason.string' => __('Укажите корректную причину.'),
            'reason.max' => __('Причина не должна превышать 500 символов.'),
        ])->after(function (ValidatorContract $validator) use ($attributes): void {
            if ($validator->errors()->isNotEmpty() || ($attributes['exception_type'] ?? null) !== ScheduleExceptionType::CustomWindow->value) {
                return;
            }

            try {
                WallClockInterval::from($attributes['start_time'], $attributes['end_time']);
            } catch (InvalidArgumentException) {
                $validator->errors()->add('end_time', __('Окончание должно быть позже начала.'));
            }
        })->validate();
        $definition = ScheduleExceptionDefinition::from($attributes);

        return DB::transaction(function () use ($actor, $organization, $definition, $specialist, $acknowledgeImpact, $acknowledgedImpactDigest): ScheduleException {
            $lockedSpecialist = Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($specialist->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $impact = $this->impactCalculator->forException($lockedSpecialist, $definition);
            $this->impactAcknowledgement->handle($impact, $acknowledgeImpact, $acknowledgedImpactDigest);
            $this->ensureNoOverlap($organization->getKey(), $lockedSpecialist->getKey(), $definition);

            $exception = new ScheduleException;
            $exception->forceFill([
                'organization_id' => $organization->getKey(),
                'specialist_id' => $lockedSpecialist->getKey(),
                'exception_date' => $definition->date,
                'exception_type' => $definition->type,
                'start_time' => $definition->interval?->start,
                'end_time' => $definition->interval?->end,
                'reason' => $definition->reason,
                'is_active' => true,
            ]);
            $exception->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'specialist.schedule.exception.created',
                targetType: ScheduleException::class,
                targetId: (string) $exception->getKey(),
                metadata: [
                    'exception_type' => $definition->type->value,
                    'source' => 'crm',
                ],
            );
            $this->recordImpactAcknowledgement($actor, $exception, $impact);

            return $exception->refresh();
        });
    }

    private function recordImpactAcknowledgement(
        User $actor,
        ScheduleException $exception,
        ScheduleMutationImpact $impact,
    ): void {
        if (! $impact->hasConflicts()) {
            return;
        }

        $this->audit->handle(
            organization: $this->context->organization(),
            actor: $actor,
            action: 'schedule.mutation.acknowledged',
            targetType: ScheduleException::class,
            targetId: (string) $exception->getKey(),
            metadata: [
                'source' => 'crm',
                'mutation' => 'schedule_exception',
                'affected_booking_count' => $impact->count(),
                'impact_digest' => $impact->digest,
            ],
        );
    }

    private function ensureNoOverlap(
        int $organizationId,
        int $specialistId,
        ScheduleExceptionDefinition $definition,
    ): void {
        $existing = ScheduleException::query()
            ->where('organization_id', $organizationId)
            ->where('specialist_id', $specialistId)
            ->where('exception_date', $definition->date)
            ->where('is_active', true)
            ->get();

        foreach ($existing as $exception) {
            if ($definition->type === ScheduleExceptionType::DayOff
                || $exception->exception_type === ScheduleExceptionType::DayOff
                || $this->overlaps($definition->interval?->startMinutes(), $definition->interval?->endMinutes(), $exception->wallClockInterval()?->startMinutes(), $exception->wallClockInterval()?->endMinutes())) {
                throw ValidationException::withMessages([
                    'exception_date' => 'The schedule exception overlaps an existing exception.',
                ]);
            }
        }
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function normalizeAttributes(array $attributes): array
    {
        if (($attributes['exception_date'] ?? null) instanceof DateTimeInterface) {
            $attributes['exception_date'] = $attributes['exception_date']->format('Y-m-d');
        }

        if (($attributes['exception_type'] ?? null) instanceof ScheduleExceptionType) {
            $attributes['exception_type'] = $attributes['exception_type']->value;
        }

        foreach (['start_time', 'end_time'] as $field) {
            if (($attributes[$field] ?? null) instanceof DateTimeInterface) {
                $attributes[$field] = $attributes[$field]->format('H:i');
            }
        }

        if (is_string($attributes['reason'] ?? null)) {
            $attributes['reason'] = trim($attributes['reason']) ?: null;
        }

        return $attributes;
    }

    private function overlaps(?int $start, ?int $end, ?int $otherStart, ?int $otherEnd): bool
    {
        return $start !== null && $end !== null && $otherStart !== null && $otherEnd !== null
            && $start < $otherEnd && $end > $otherStart;
    }
}
