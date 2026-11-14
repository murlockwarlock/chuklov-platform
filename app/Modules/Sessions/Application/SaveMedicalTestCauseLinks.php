<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Domain\Models\MedicalTestCauseLink;
use App\Modules\Sessions\Domain\Models\MedicalTestDefinition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SaveMedicalTestCauseLinks
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private MedicalCauseDirectory $directory,
        private RecordAuditEvent $audit,
    ) {}

    /** @param array<int, mixed> $causeIds */
    public function handle(User $actor, MedicalTestDefinition|int $test, array $causeIds, ?string $scopeKey = null): Collection
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        $organizationId = (int) $organization->getKey();
        $testId = $test instanceof MedicalTestDefinition ? (int) $test->getKey() : $test;
        $scopeKey = $this->normalizeScopeKey($scopeKey);
        $selectedCauseIds = $this->canonicalSelectableIds($organizationId, $causeIds);

        return DB::transaction(function () use ($actor, $organization, $organizationId, $testId, $scopeKey, $selectedCauseIds): Collection {
            $definition = MedicalTestDefinition::query()
                ->where('organization_id', $organizationId)
                ->whereKey($testId)
                ->lockForUpdate()
                ->first();
            if ($definition === null) {
                throw ValidationException::withMessages(['test' => 'Выбранный тест не найден.']);
            }

            $existingQuery = MedicalTestCauseLink::query()
                ->where('organization_id', $organizationId)
                ->where('medical_test_definition_id', $testId);
            $existingQuery = $scopeKey === null
                ? $existingQuery->whereNull('scope_key')
                : $existingQuery->where('scope_key', $scopeKey);
            $existingQuery->lockForUpdate()->get();
            $existingQuery->update(['is_active' => false]);

            foreach ($selectedCauseIds as $causeId) {
                $linkQuery = MedicalTestCauseLink::query()
                    ->where('organization_id', $organizationId)
                    ->where('medical_test_definition_id', $testId)
                    ->where('medical_cause_definition_id', $causeId);
                $linkQuery = $scopeKey === null
                    ? $linkQuery->whereNull('scope_key')
                    : $linkQuery->where('scope_key', $scopeKey);
                $link = $linkQuery->lockForUpdate()->first() ?? new MedicalTestCauseLink;
                $link->forceFill([
                    'organization_id' => $organizationId,
                    'medical_test_definition_id' => $testId,
                    'medical_cause_definition_id' => $causeId,
                    'scope_key' => $scopeKey,
                    'is_active' => true,
                ])->save();
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.catalog.updated',
                targetType: MedicalTestDefinition::class,
                targetId: (string) $definition->getKey(),
                metadata: [],
            );

            $resultQuery = MedicalTestCauseLink::query()
                ->where('organization_id', $organizationId)
                ->where('medical_test_definition_id', $testId)
                ->where('is_active', true);
            $resultQuery = $scopeKey === null
                ? $resultQuery->whereNull('scope_key')
                : $resultQuery->where('scope_key', $scopeKey);

            return $resultQuery->orderBy('medical_cause_definition_id')->get();
        });
    }

    /** @param array<int, mixed> $causeIds
     * @return list<int>
     */
    private function canonicalSelectableIds(int $organizationId, array $causeIds): array
    {
        $ids = [];
        foreach ($causeIds as $causeId) {
            $causeId = (int) $causeId;
            if ($causeId <= 0) {
                continue;
            }
            $ids[] = $this->directory->selectable($organizationId, $causeId)->getKey();
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function normalizeScopeKey(?string $scopeKey): ?string
    {
        if ($scopeKey === null) {
            return null;
        }
        $scopeKey = trim($scopeKey);
        if ($scopeKey === '') {
            return null;
        }
        if (mb_strlen($scopeKey) > 160) {
            throw ValidationException::withMessages(['scope_key' => 'Ключ области теста слишком длинный.']);
        }

        return $scopeKey;
    }
}
