<?php

namespace App\Modules\Services\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Services\Domain\Models\Service;

class UpdateService
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(Service $service, string $name, string $summary, bool $isActive): Service
    {
        abort_unless($service->organization_id === $this->context->id(), 404);

        $service->update([
            'name' => $name,
            'summary' => $summary,
            'is_active' => $isActive,
        ]);

        return $service->refresh();
    }
}
