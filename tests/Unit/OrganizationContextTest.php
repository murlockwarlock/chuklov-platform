<?php

namespace Tests\Unit;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use LogicException;
use PHPUnit\Framework\TestCase;

class OrganizationContextTest extends TestCase
{
    public function test_it_requires_server_resolution(): void
    {
        $this->expectException(LogicException::class);

        (new OrganizationContext)->id();
    }

    public function test_it_returns_the_resolved_organization(): void
    {
        $organization = new Organization;
        $organization->id = 42;
        $context = new OrganizationContext;

        $context->set($organization);

        self::assertSame(42, $context->id());
    }
}
