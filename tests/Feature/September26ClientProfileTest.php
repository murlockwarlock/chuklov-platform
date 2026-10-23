<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Application\ClientProfileSnapshotHasher;
use App\Modules\Identity\Application\UpdateClientProfileFromCrm;
use App\Modules\Identity\Application\UpdateClientProfileFromPortal;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class September26ClientProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_name_is_preserved_but_never_used_as_a_confirmed_greeting(): void
    {
        $client = $this->client();
        $client->update(['full_name' => 'Эксперт 123']);
        $this->withSession(['client_portal.client_id' => $client->id]);
        $this->get(route('portal.home'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('portal.clientName', 'Эксперт 123')
            ->where('portal.confirmedFirstName', null));
        $this->post(route('portal.profile.update'), ['phone' => '+77001234567'])->assertRedirect();
        self::assertSame('Эксперт 123', $client->refresh()->full_name);
        self::assertNull($client->name_confirmed_at);
    }

    public function test_portal_demographics_persist_and_are_readable_in_crm_then_portal_after_correction(): void
    {
        $client = $this->client();
        $this->withSession(['client_portal.client_id' => $client->id]);
        $data = $this->demographics();
        $this->post(route('portal.profile.update'), $data)->assertSessionHasNoErrors()->assertRedirect(route('portal.profile'));
        $client->refresh();
        self::assertSame('Солдатов Денис Сергеевич', $client->full_name);
        self::assertNotNull($client->name_confirmed_at);
        self::assertSame('1990-02-28', $client->birth_date->toDateString());
        $this->get(route('portal.profile'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('saved', true)
            ->where('profile.firstName', 'Денис')->where('profile.lastName', 'Солдатов')
            ->where('profile.gender', 'MALE')->where('profile.birthDate', '1990-02-28')
            ->where('profile.country', 'Казахстан')->where('profile.city', 'Алматы'));
        $this->get(route('portal.home'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('portal.confirmedFirstName', 'Денис'));
        $actor = User::factory()->forOrganization($client->organization)->create();
        app(UpdateClientProfileFromCrm::class)->handle($actor, $client, ['city' => 'Астана']);
        $this->get(route('portal.profile'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('profile.city', 'Астана')->where('profile.firstName', 'Денис'));
    }

    public function test_invalid_demographics_leave_existing_profile_unchanged(): void
    {
        $client = $this->client();
        $this->withSession(['client_portal.client_id' => $client->id]);
        foreach ([['gender' => 'OTHER'], ['birth_date' => '1990-02-30'], ['birth_date' => '2990-01-01'], ['first_name' => '']] as $invalid) {
            $this->post(route('portal.profile.update'), [...$this->demographics(), ...$invalid])->assertSessionHasErrors(array_keys($invalid));
            self::assertNull($client->refresh()->first_name);
            self::assertNull($client->name_confirmed_at);
        }
    }

    public function test_crm_snapshot_detects_demographics_changed_in_another_tab(): void
    {
        $client = $this->client();
        $snapshot = app(ClientProfileSnapshotHasher::class)->forClient($client);
        app(UpdateClientProfileFromPortal::class)->handle($client, ['city' => 'Алматы'], ['city']);
        $actor = User::factory()->forOrganization($client->organization)->create();
        $this->expectException(ValidationException::class);
        app(UpdateClientProfileFromCrm::class)->handle($actor, $client, ['city' => 'Астана', 'expected_snapshot' => $snapshot]);
    }

    public function test_structured_profile_requires_all_mandatory_fields_and_rejects_invalid_timezone(): void
    {
        $client = $this->client();
        $this->withSession(['client_portal.client_id' => $client->id]);
        $this->post(route('portal.profile.update'), ['first_name' => 'Денис'])
            ->assertSessionHasErrors(['last_name', 'gender', 'birth_date', 'country', 'city', 'phone']);
        self::assertNull($client->refresh()->first_name);
        foreach ([null, 'Not/A_Timezone'] as $timezone) {
            $this->post(route('portal.profile.update'), ['timezone' => $timezone])->assertSessionHasErrors('timezone');
            self::assertNotNull($client->refresh()->timezone);
        }
    }

    public function test_foreign_client_cannot_be_updated_through_application(): void
    {
        $client = $this->client();
        $foreign = Client::factory()->forOrganization(Organization::factory()->create())->create();
        $this->expectException(AuthorizationException::class);
        app(UpdateClientProfileFromPortal::class)->handle($foreign, ['first_name' => 'Денис', 'last_name' => 'Солдатов'], ['first_name', 'last_name']);
    }

    private function client(): Client
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        config(['tenancy.default_organization_id' => $organization->id]);
        $organization->featureFlags()->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);

        return Client::factory()->forOrganization($organization)->create();
    }

    private function demographics(): array
    {
        return [
            'first_name' => 'Денис', 'last_name' => 'Солдатов', 'middle_name' => 'Сергеевич',
            'gender' => 'MALE', 'birth_date' => '1990-02-28', 'country' => 'Казахстан', 'city' => 'Алматы',
            'phone' => '+77001234567',
        ];
    }
}
