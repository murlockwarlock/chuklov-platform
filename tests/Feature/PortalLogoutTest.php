<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class PortalLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_logout_invalidates_session_records_audit_and_confirms_the_result(): void
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        $client = Client::factory()->forOrganization($organization)->create();

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.more'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/More')
                ->where('logoutUrl', route('portal.logout')));
        $authenticatedSessionId = session()->getId();

        $this->post(route('portal.logout'))
            ->assertRedirect(route('portal.home'))
            ->assertSessionHas('portal_logged_out', true)
            ->assertSessionMissing('client_portal.client_id');
        self::assertNotSame($authenticatedSessionId, session()->getId());

        $this->get(route('portal.home'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Entry')
                ->where('portal.authenticated', false)
                ->where('auth.loggedOut', true));

        $this->get(route('portal.medical-profile'))->assertUnauthorized();

        $event = AuditEvent::query()->where('action', 'client.portal.logged_out')->sole();
        self::assertSame($organization->getKey(), $event->organization_id);
        self::assertSame('client', $event->target_type);
        self::assertSame((string) $client->getKey(), $event->target_id);
        self::assertSame('client', $event->metadata['actor_type']);
        self::assertSame($client->getKey(), $event->metadata['actor_client_id']);
        self::assertSame('portal', $event->metadata['source']);
    }

    public function test_anonymous_client_cannot_log_out_or_create_a_logout_audit_event(): void
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());

        $this->get(route('portal.more'))->assertUnauthorized();
        $this->post(route('portal.logout'))->assertUnauthorized();
        $this->get(route('portal.home'))->assertOk();

        $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => Inertia::getVersion(),
        ])
            ->get(route('portal.more'))
            ->assertRedirect(route('portal.home'));

        self::assertSame(0, AuditEvent::query()->where('action', 'client.portal.logged_out')->count());
    }
}
