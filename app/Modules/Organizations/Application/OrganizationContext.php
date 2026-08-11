<?php

namespace App\Modules\Organizations\Application;

use App\Modules\Organizations\Domain\Models\Organization;
use LogicException;

class OrganizationContext
{
    private ?Organization $organization = null;

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function organization(): Organization
    {
        return $this->organization ?? throw new LogicException('Organization context is not resolved.');
    }

    public function id(): int
    {
        return $this->organization()->getKey();
    }
}
