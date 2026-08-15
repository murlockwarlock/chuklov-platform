<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use Illuminate\Validation\ValidationException;

final class SpecialistServiceAssignmentEligibility
{
    public function exists(int $organizationId, int $specialistId, int $serviceId): bool
    {
        return SpecialistServiceAssignment::query()
            ->where('organization_id', $organizationId)
            ->where('specialist_id', $specialistId)
            ->where('service_id', $serviceId)
            ->exists();
    }

    public function ensure(int $organizationId, int $specialistId, int $serviceId): void
    {
        if (! $this->exists($organizationId, $specialistId, $serviceId)) {
            throw ValidationException::withMessages([
                'assignment' => 'The specialist is not assigned to this service.',
            ]);
        }
    }

    public static function invalidate(): void
    {
        // No-op
    }
}
