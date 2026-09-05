<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\User;
use App\Modules\Attribution\Application\AcceptManualAttribution;
use App\Modules\Attribution\Application\CapturePreAuthAttribution;
use App\Modules\Attribution\Application\ManageAttributionSourceDetail;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Attribution\Domain\Models\PreAuthAttribution;
use App\Modules\Identity\Application\RegisterClientAcquisition;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Referrals\Application\FinalizeClientAcquisition;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Security\Domain\Models\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrganicRecommendationDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_pre_auth_detail_survives_acquisition_encrypted_without_matching_or_referral_side_effects(): void
    {
        [$organization, $actor, $client] = $this->fixture();
        Client::factory()->forOrganization($organization)->create(['full_name' => 'Анна', 'phone' => '+77001112233']);
        $sessionId = session()->getId();
        app(CapturePreAuthAttribution::class)->handle(
            $sessionId,
            ['source' => 'friend'],
            sourceDetail: " Анна\n @anna +77001112233 ",
        );
        $preAuth = PreAuthAttribution::query()->sole();
        self::assertStringNotContainsString('@anna', $preAuth->encrypted_source_detail);
        self::assertArrayNotHasKey('encrypted_source_detail', $preAuth->toArray());

        app(RegisterClientAcquisition::class)->handle($organization, $client, $sessionId);
        app(FinalizeClientAcquisition::class)->handle($client, $sessionId);
        $record = ClientAttribution::query()->sole();
        self::assertSame('Анна @anna +77001112233', app(ManageAttributionSourceDetail::class)->read($actor, $client));
        self::assertSame($preAuth->encrypted_source_detail, $record->encrypted_source_detail);
        self::assertSame(1, (int) $record->source_detail_key_version);
        self::assertArrayNotHasKey('encrypted_source_detail', $record->toArray());
        self::assertSame(0, ReferralRelationship::query()->count());
        self::assertNull($record->referral_code);
        self::assertSame('friend', $client->fresh()->lead_source);
        self::assertStringNotContainsString('@anna', AuditEvent::query()->get()->toJson());

        app(FinalizeClientAcquisition::class)->handle($client, $sessionId);
        self::assertSame(1, ClientAttribution::query()->count());
        $this->withSession(['client_portal.client_id' => $client->id])
            ->get(route('portal.attribution'))->assertDontSee('@anna');
    }

    public function test_crm_can_read_edit_and_clear_detail_without_changing_source(): void
    {
        [, $actor, $client] = $this->fixture();
        app(AcceptManualAttribution::class)->handle($client, 'other', 'Встреча');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($actor)->test(ViewClient::class, ['record' => $client->id])
            ->mountAction('sourceDetail')
            ->assertSchemaStateSet(['source_detail' => 'Встреча'])
            ->fillForm(['source_detail' => 'На конференции'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        self::assertSame('На конференции', app(ManageAttributionSourceDetail::class)->read($actor, $client));
        self::assertSame('other', ClientAttribution::query()->sole()->source);
        app(ManageAttributionSourceDetail::class)->update($actor, $client, '   ');
        self::assertNull(app(ManageAttributionSourceDetail::class)->read($actor, $client));
        self::assertNull(DB::table('client_attributions')->value('encrypted_source_detail'));
        self::assertNull(DB::table('client_attributions')->value('source_detail_key_version'));
        self::assertSame(0, ReferralRelationship::query()->count());
    }

    public function test_foreign_clients_and_unprivileged_users_cannot_read_or_edit(): void
    {
        [, $actor, $client] = $this->fixture();
        app(AcceptManualAttribution::class)->handle($client, 'friend', 'Private note');
        $foreign = Client::factory()->forOrganization(Organization::factory()->create())->create();
        $outsider = User::factory()->create();
        $service = app(ManageAttributionSourceDetail::class);
        foreach (['read', 'update'] as $method) {
            try {
                if ($method === 'read') {
                    $service->read($actor, $foreign);
                } else {
                    $service->update($actor, $foreign, 'Changed');
                }
                self::fail('Cross-organization access must fail.');
            } catch (HttpException $exception) {
                self::assertSame(404, $exception->getStatusCode());
            }
            try {
                if ($method === 'read') {
                    $service->read($outsider, $client);
                } else {
                    $service->update($outsider, $client, 'Changed');
                }
                self::fail('Missing permission must fail.');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('Private note', $service->read($actor, $client));
    }

    public function test_detail_is_bounded_and_only_allowed_for_friend_or_other(): void
    {
        [, , $client] = $this->fixture();
        foreach ([['friend', str_repeat('я', 501)], ['friend', ['invalid']], ['social', 'Secret']] as [$source, $detail]) {
            try {
                app(AcceptManualAttribution::class)->handle($client, $source, $detail);
                self::fail('Invalid detail must fail.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('source_detail', $exception->errors());
            }
        }
        self::assertSame(0, ClientAttribution::query()->count());
        $record = app(AcceptManualAttribution::class)->handle($client, 'friend', str_repeat('я', 500));
        self::assertNotNull($record->encrypted_source_detail);
    }

    public function test_existing_first_touch_cannot_be_overwritten_by_later_detail(): void
    {
        [$organization, $actor, $client] = $this->fixture();
        app(CapturePreAuthAttribution::class)->handle('first-session', ['source' => 'friend'], sourceDetail: 'First');
        app(CapturePreAuthAttribution::class)->handle('first-session', ['source' => 'other'], sourceDetail: 'Later');
        app(RegisterClientAcquisition::class)->handle($organization, $client, 'first-session');
        app(FinalizeClientAcquisition::class)->handle($client, 'first-session');
        app(AcceptManualAttribution::class)->handle($client, 'other', 'Later');
        self::assertSame('First', app(ManageAttributionSourceDetail::class)->read($actor, $client));
        self::assertSame('friend', ClientAttribution::query()->sole()->source);
    }

    public function test_detail_is_not_captured_from_query_parameters_or_flashed_on_validation_failure(): void
    {
        [, , $client] = $this->fixture();
        $this->get(route('portal.home', ['source' => 'friend', 'source_detail' => 'URL detail']))->assertOk();
        $preAuth = PreAuthAttribution::query()->sole();
        self::assertNull($preAuth->encrypted_source_detail);

        $this->withSession(['client_portal.client_id' => $client->id]);
        $this->post(route('portal.attribution.update'), ['source' => 'invalid', 'source_detail' => 'Protected'])
            ->assertSessionHasErrors('source')
            ->assertSessionMissing('_old_input.source_detail');
    }

    public function test_authenticated_portal_can_save_manual_recommendation_detail(): void
    {
        [$organization, , $client] = $this->fixture();
        $this->withSession(['client_portal.client_id' => $client->id])
            ->post(route('portal.attribution.update'), [
                'source' => 'friend',
                'source_detail' => "  @anna\n+7 700 111 22 33  ",
            ])
            ->assertRedirect(route('portal.home'));

        $actor = User::factory()->forOrganization($organization)->create();
        self::assertSame('@anna +7 700 111 22 33', app(ManageAttributionSourceDetail::class)->read($actor, $client));
    }

    /** @return array{Organization, User, Client} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);

        return [
            $organization,
            User::factory()->forOrganization($organization)->create(),
            Client::factory()->forOrganization($organization)->create(['lead_source' => null]),
        ];
    }
}
