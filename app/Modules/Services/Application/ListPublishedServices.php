<?php

namespace App\Modules\Services\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Database\Eloquent\Collection;

class ListPublishedServices
{
    public function __construct(private readonly OrganizationContext $context) {}

    /** @return Collection<int, Service> */
    public function handle(): Collection
    {
        return Service::query()
            ->where('organization_id', $this->context->id())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
