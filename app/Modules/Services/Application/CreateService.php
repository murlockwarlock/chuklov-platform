<?php

namespace App\Modules\Services\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Services\Domain\Models\Service;

class CreateService
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(string $name, string $summary, bool $isActive): Service
    {
        return Service::query()->create([
            'organization_id' => $this->context->id(),
            'name' => $name,
            'summary' => $summary,
            'is_active' => $isActive,
        ]);
    }
}
