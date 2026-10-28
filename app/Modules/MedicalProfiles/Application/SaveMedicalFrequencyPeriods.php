<?php

namespace App\Modules\MedicalProfiles\Application;

use App\Models\User;
use App\Modules\MedicalProfiles\Domain\Models\MedicalFrequencyPeriodUnit;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SaveMedicalFrequencyPeriods
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private RecordAuditEvent $audit,
    ) {}

    /** @param array<int, mixed> $rows */
    public function handle(User $actor, array $rows): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        $normalized = $this->normalize($rows);

        DB::transaction(function () use ($organization, $actor, $normalized): void {
            $locked = $organization->newQuery()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $existing = MedicalFrequencyPeriodUnit::query()
                ->where('organization_id', $locked->getKey())
                ->lockForUpdate()
                ->get()
                ->keyBy('key');
            $keys = [];

            foreach ($normalized as $row) {
                $keys[$row['key']] = true;
                $unit = $existing->get($row['key']) ?? new MedicalFrequencyPeriodUnit;
                $unit->forceFill([
                    'organization_id' => $locked->getKey(),
                    'key' => $row['key'],
                    'label' => $row['label'],
                    'is_active' => $row['is_active'],
                    'sort_order' => $row['sort_order'],
                ])->save();
            }

            foreach ($existing as $key => $unit) {
                if (! isset($keys[$key])) {
                    $unit->forceFill(['is_active' => false])->save();
                }
            }

            $this->audit->handle($locked, $actor, 'medical.frequency_periods.updated', MedicalFrequencyPeriodUnit::class, (string) ($normalized[0]['key'] ?? 'catalog'), [
                'count' => count($normalized),
            ]);
        });
    }

    /** @param array<int, mixed> $rows */
    /** @return list<array{key: string, label: string, is_active: bool, sort_order: int}> */
    private function normalize(array $rows): array
    {
        $normalized = [];
        $keys = [];
        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(['periods' => 'Проверьте список периодов.']);
            }
            $key = is_string($row['key'] ?? null) ? trim($row['key']) : '';
            $label = is_string($row['label'] ?? null) ? trim($row['label']) : '';
            $sortOrder = filter_var($row['sort_order'] ?? $index, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($key === '' || preg_match('/^[a-z][a-z0-9_]{1,39}$/', $key) !== 1) {
                throw ValidationException::withMessages(['periods' => 'Укажите корректный идентификатор периода.']);
            }
            if ($label === '' || mb_strlen($label) > 120) {
                throw ValidationException::withMessages(['periods' => 'Укажите название периода.']);
            }
            if ($sortOrder === false) {
                throw ValidationException::withMessages(['periods' => 'Укажите корректный порядок периодов.']);
            }
            if (isset($keys[$key])) {
                throw ValidationException::withMessages(['periods' => 'Идентификаторы периодов должны отличаться.']);
            }
            $keys[$key] = true;
            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'is_active' => (bool) ($row['is_active'] ?? true),
                'sort_order' => (int) $sortOrder,
            ];
        }

        return $normalized;
    }
}
