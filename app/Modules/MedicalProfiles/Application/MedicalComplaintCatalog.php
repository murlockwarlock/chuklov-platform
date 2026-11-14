<?php

namespace App\Modules\MedicalProfiles\Application;

use App\Models\User;
use App\Modules\MedicalProfiles\Domain\Models\MedicalComplaintDefinition;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Validation\ValidationException;

final readonly class MedicalComplaintCatalog
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
    ) {}

    /** @return array<int, string> */
    public function options(int $organizationId, array $selectedIds = []): array
    {
        $options = MedicalComplaintDefinition::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'group_name'])
            ->mapWithKeys(fn (MedicalComplaintDefinition $complaint): array => [
                (int) $complaint->getKey() => $this->label($complaint),
            ])
            ->all();

        $selected = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $selectedIds)));
        if ($selected === []) {
            return $options;
        }

        MedicalComplaintDefinition::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $selected)
            ->where('is_active', false)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'group_name'])
            ->each(function (MedicalComplaintDefinition $complaint) use (&$options): void {
                $options[(int) $complaint->getKey()] = $this->label($complaint).' (неактивна)';
            });

        return $options;
    }

    /** @return list<array{id:int,name:string,group_name:?string}> */
    public function items(int $organizationId): array
    {
        return MedicalComplaintDefinition::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'group_name'])
            ->map(static fn (MedicalComplaintDefinition $complaint): array => [
                'id' => (int) $complaint->getKey(),
                'name' => (string) $complaint->name,
                'group_name' => $complaint->group_name !== null ? (string) $complaint->group_name : null,
            ])->all();
    }

    /** @return array<int, string> */
    public function search(int $organizationId, string $search, array $selectedIds = []): array
    {
        $needle = trim($search);
        if ($needle === '') {
            return $this->options($organizationId, $selectedIds);
        }

        $rows = MedicalComplaintDefinition::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($needle): void {
                $query->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('group_name', 'like', '%'.$needle.'%')
                    ->orWhereRaw('CAST(synonyms AS TEXT) ILIKE ?', ['%'.$needle.'%']);
            })
            ->where(function ($query) use ($selectedIds): void {
                $query->where('is_active', true)
                    ->orWhereIn('id', array_values(array_map(static fn (mixed $id): int => (int) $id, $selectedIds)));
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'group_name']);

        return $rows->mapWithKeys(fn (MedicalComplaintDefinition $complaint): array => [
            (int) $complaint->getKey() => $this->label($complaint),
        ])->all();
    }

    public function createForSession(User $actor, string $name, ?string $groupName = null): int
    {
        $organization = $this->context->organization();
        if (! $this->authorizer->allows($actor, $organization, OrganizationPermission::ManageClients)
            && ! $this->authorizer->allows($actor, $organization, OrganizationPermission::ManageSettings)) {
            throw ValidationException::withMessages(['pains' => 'Недостаточно прав для добавления жалобы.']);
        }

        $name = trim($name);
        $groupName = filled($groupName) ? trim((string) $groupName) : null;
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['pains' => 'Укажите название жалобы.']);
        }

        $complaint = MedicalComplaintDefinition::query()->firstOrCreate(
            ['organization_id' => $organization->getKey(), 'name' => $name],
            ['group_name' => $groupName, 'is_active' => true, 'sort_order' => $this->nextOrder((int) $organization->getKey())],
        );
        if (! $complaint->is_active) {
            $complaint->forceFill(['is_active' => true, 'group_name' => $groupName])->save();
        }

        return (int) $complaint->getKey();
    }

    public function name(int $organizationId, int $id): ?string
    {
        $name = MedicalComplaintDefinition::query()
            ->where('organization_id', $organizationId)
            ->whereKey($id)
            ->value('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /** @param array<int, mixed> $ids */
    public function assertKnown(int $organizationId, array $ids, array $preserveInactiveIds = []): array
    {
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $ids)));
        $preserved = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $preserveInactiveIds)));
        if ($ids === []) {
            return [];
        }
        if (count($ids) > 50 || in_array(0, $ids, true)) {
            throw ValidationException::withMessages(['pains' => 'Выберите не более 50 жалоб.']);
        }

        $known = MedicalComplaintDefinition::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
        $active = MedicalComplaintDefinition::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
        $allowed = array_values(array_unique([...$active, ...array_intersect($known, $preserved)]));
        sort($allowed);
        sort($ids);
        if ($allowed !== $ids) {
            throw ValidationException::withMessages(['pains' => 'Выбранная жалоба недоступна для этой организации.']);
        }

        return $allowed;
    }

    private function nextOrder(int $organizationId): int
    {
        return ((int) MedicalComplaintDefinition::query()->where('organization_id', $organizationId)->max('sort_order')) + 1;
    }

    private function label(MedicalComplaintDefinition $complaint): string
    {
        return filled($complaint->group_name)
            ? $complaint->group_name.': '.$complaint->name
            : $complaint->name;
    }
}
