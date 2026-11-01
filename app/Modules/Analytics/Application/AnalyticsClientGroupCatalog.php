<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Domain\Models\AnalyticsClientGroup;
use App\Modules\Organizations\Application\OrganizationContext;

final readonly class AnalyticsClientGroupCatalog
{
    public function __construct(private OrganizationContext $context) {}

    /** @return array<int, string> */
    public function options(bool $activeOnly = true): array
    {
        return AnalyticsClientGroup::query()
            ->where('organization_id', $this->context->id())
            ->when($activeOnly, fn ($query) => $query->where('is_active', true)->whereNull('archived_at'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(bool $includeArchived = false): array
    {
        $groups = AnalyticsClientGroup::query()
            ->where('organization_id', $this->context->id())
            ->when(! $includeArchived, fn ($query) => $query->where('is_active', true)->whereNull('archived_at'))
            ->with(['clients:id'])
            ->withCount('clients')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $rows = [];
        foreach ($groups as $group) {
            $rows[] = [
                'id' => (int) $group->getKey(),
                'name' => (string) $group->name,
                'description' => $group->description,
                'group_type' => (string) $group->group_type,
                'is_active' => (bool) $group->is_active,
                'sort_order' => (int) $group->sort_order,
                'version' => (int) $group->version,
                'rule_definition' => $group->rule_definition,
                'client_count' => (int) $group->clients_count,
                'client_ids' => $group->clients->modelKeys(),
                'archived_at' => $group->archived_at?->toIso8601String(),
            ];
        }

        return $rows;
    }
}
