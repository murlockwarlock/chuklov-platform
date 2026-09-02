<?php

namespace App\Modules\Scenarios\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Models\AppointmentReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateAppointmentReminders
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorization,
        private readonly AppointmentReminderScheduler $scheduler,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data): void
    {
        $organization = $this->context->organization();
        $this->authorization->authorize($actor, $organization, OrganizationPermission::ManageScheduling);
        $incoming = [
            ...$this->normalize($data['client_reminders'] ?? [], 'client'),
            ...$this->normalize($data['specialist_reminders'] ?? [], 'specialist'),
        ];

        DB::transaction(function () use ($organization, $incoming): void {
            $existing = AppointmentReminder::query()
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (AppointmentReminder $reminder): string => $this->key(
                    $reminder->recipient_type,
                    $reminder->offset_value,
                    $reminder->offset_unit->value,
                ));

            foreach ($incoming as $definition) {
                $key = $this->key($definition['recipient_type'], $definition['offset_value'], $definition['offset_unit']);
                $reminder = $existing->get($key);
                if ($reminder instanceof AppointmentReminder) {
                    $reminder->forceFill(['is_enabled' => $definition['is_enabled']])->save();
                    $existing->forget($key);

                    continue;
                }

                $reminder = new AppointmentReminder;
                $reminder->forceFill([
                    'organization_id' => $organization->getKey(),
                    'recipient_type' => $definition['recipient_type'],
                    'offset_value' => $definition['offset_value'],
                    'offset_unit' => $definition['offset_unit'],
                    'is_enabled' => $definition['is_enabled'],
                ])->save();
            }

            foreach ($existing as $reminder) {
                $reminder->forceFill(['is_enabled' => false])->save();
            }

            $this->scheduler->rebuildForOrganization((int) $organization->getKey());
        });
    }

    /** @return list<array{recipient_type: string, offset_value: int, offset_unit: string, is_enabled: bool}> */
    private function normalize(mixed $value, string $recipientType): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 10) {
            throw ValidationException::withMessages([$recipientType.'_reminders' => 'Добавьте не больше 10 напоминаний.']);
        }

        $result = [];
        $keys = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([$recipientType.'_reminders' => 'Проверьте время напоминания.']);
            }
            $offset = filter_var($item['offset_value'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
            $unit = ScenarioDelayUnit::tryFrom((string) ($item['offset_unit'] ?? ''));
            if ($offset === null || $offset < 1 || $unit === null) {
                throw ValidationException::withMessages([$recipientType.'_reminders' => 'Укажите корректное время напоминания.']);
            }
            $maximum = [
                'minutes' => 525600,
                'hours' => 8760,
                'days' => 365,
            ][$unit->value];
            if ($offset > $maximum) {
                throw ValidationException::withMessages([$recipientType.'_reminders' => 'Укажите корректное время напоминания.']);
            }
            $key = $this->key($recipientType, $offset, $unit->value);
            if (isset($keys[$key])) {
                throw ValidationException::withMessages([$recipientType.'_reminders' => 'Время напоминания не должно повторяться.']);
            }
            $keys[$key] = true;
            $result[] = [
                'recipient_type' => $recipientType,
                'offset_value' => $offset,
                'offset_unit' => $unit->value,
                'is_enabled' => (bool) ($item['is_enabled'] ?? true),
            ];
        }

        return $result;
    }

    private function key(string $recipientType, int $value, string $unit): string
    {
        return $recipientType.'|'.$value.'|'.$unit;
    }
}
