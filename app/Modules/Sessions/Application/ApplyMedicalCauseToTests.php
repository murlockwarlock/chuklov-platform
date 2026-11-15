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

final readonly class ApplyMedicalCauseToTests
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private MedicalCauseDirectory $directory,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array<int, mixed>  $testIds
     * @return Collection<int, MedicalTestCauseLink>
     */
    public function handle(User $actor, int $causeId, array $testIds, ?string $scopeKey = null): Collection
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);
        $organizationId = (int) $organization->getKey();
        $cause = $this->directory->selectable($organizationId, $causeId);
        $scopeKey = $this->normalizeScopeKey($scopeKey);
        $testIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $testIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($testIds === []) {
            throw ValidationException::withMessages(['tests' => 'Выберите хотя бы один тест.']);
        }

        return DB::transaction(function () use ($actor, $organization, $organizationId, $cause, $testIds, $scopeKey): Collection {
            $tests = MedicalTestDefinition::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $testIds)
                ->lockForUpdate()
                ->get();
            if ($tests->count() !== count($testIds)) {
                throw ValidationException::withMessages(['tests' => 'Один из выбранных тестов недоступен.']);
            }

            foreach ($tests as $test) {
                $query = MedicalTestCauseLink::query()
                    ->where('organization_id', $organizationId)
                    ->where('medical_test_definition_id', $test->getKey())
                    ->where('medical_cause_definition_id', $cause->getKey());
                $query = $scopeKey === null
                    ? $query->whereNull('scope_key')
                    : $query->where('scope_key', $scopeKey);
                $link = $query->lockForUpdate()->first() ?? new MedicalTestCauseLink;
                $link->forceFill([
                    'organization_id' => $organizationId,
                    'medical_test_definition_id' => $test->getKey(),
                    'medical_cause_definition_id' => $cause->getKey(),
                    'scope_key' => $scopeKey,
                    'is_active' => true,
                ])->save();
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.catalog.updated',
                targetType: MedicalTestDefinition::class,
                targetId: implode(',', $testIds),
                metadata: [],
            );

            $resultQuery = MedicalTestCauseLink::query()
                ->where('organization_id', $organizationId)
                ->whereIn('medical_test_definition_id', $testIds)
                ->where('medical_cause_definition_id', $cause->getKey())
                ->where('is_active', true);
            $resultQuery = $scopeKey === null
                ? $resultQuery->whereNull('scope_key')
                : $resultQuery->where('scope_key', $scopeKey);

            return $resultQuery->orderBy('medical_test_definition_id')->get();
        });
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
