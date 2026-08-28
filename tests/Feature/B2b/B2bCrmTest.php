<?php

namespace Tests\Feature\B2b;

use App\Filament\Resources\B2bLeads\Pages\ListB2bLeads;
use App\Models\User;
use App\Modules\B2B\Application\ListB2bLeadsForCrm;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class B2bCrmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 27, 10, 0, 0, 'UTC'));
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_crm_list_is_organization_scoped_and_uses_the_paginated_resource(): void
    {
        [$organization, $admin, $ownLead] = $this->leadFixture();
        [$foreignOrganization, , $foreignLead] = $this->leadFixture();
        $this->setOrganization($organization);
        $panel = Filament::getPanel('admin');
        self::assertNotNull($panel);
        Filament::setCurrentPanel($panel);

        $component = Livewire::actingAs($admin)
            ->test(ListB2bLeads::class)
            ->assertCanSeeTableRecords([$ownLead])
            ->assertCanNotSeeTableRecords([$foreignLead])
            ->assertSee('Настроить слоты и Zoom')
            ->assertSee('Длительность: Требуется действие');

        self::assertSame($organization->getKey(), $ownLead->organization_id);
        self::assertSame($foreignOrganization->getKey(), $foreignLead->organization_id);
        self::assertTrue($component->instance()->getTable()->isPaginated());
    }

    public function test_organizationless_staff_cannot_open_the_b2b_resource(): void
    {
        [$organization] = $this->leadFixture();
        $this->setOrganization($organization);
        $user = User::factory()->create();
        $panel = Filament::getPanel('admin');
        self::assertNotNull($panel);
        Filament::setCurrentPanel($panel);

        try {
            B2bLead::query()->where('organization_id', $organization->getKey())->get();
            app(ListB2bLeadsForCrm::class)->query($user)->get();
            self::fail('An organizationless CRM user was allowed to list B2B leads.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    /** @return array{Organization, User, B2bLead} */
    private function leadFixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $lead = B2bLead::factory()->forClient($client)->create();
        B2bSalesCall::factory()->forLead($lead)->forSpecialist($specialist)->create();

        return [$organization, $admin, $lead];
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }
}
