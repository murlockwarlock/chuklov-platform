<?php

namespace App\Modules\B2B\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class ListEligibleB2bSalesCallSpecialists
{
    public function __construct(private readonly OrganizationContext $context) {}

    /** @return Collection<int, Specialist> */
    public function handle(): Collection
    {
        return $this->baseQuery()
            ->orderBy('display_name')
            ->limit((int) config('b2b.availability.max_specialists', 20))
            ->get(['id', 'organization_id', 'display_name', 'timezone', 'is_active']);
    }

    public function exists(): bool
    {
        return $this->baseQuery()->exists();
    }

    /** @return Builder<Specialist> */
    private function baseQuery(): Builder
    {
        $organizationId = $this->context->id();
        $scheduledSpecialists = SpecialistWorkingHour::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->select('specialist_id')
            ->distinct();

        return Specialist::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereIn('id', $scheduledSpecialists);
    }
}
