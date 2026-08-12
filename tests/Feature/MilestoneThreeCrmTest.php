<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Specialists\SpecialistResource;
use App\Models\User;
use App\Modules\Content\Application\CreateContentSection;
use App\Modules\Content\Application\ListPublishedContentSections;
use App\Modules\Identity\Application\BlockClientSelfBooking;
use App\Modules\Identity\Application\UnblockClientSelfBooking;
use App\Modules\Identity\Application\UpdateClientProfileFromCrm;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Services\Application\CreateService;
use App\Modules\Services\Application\UpdateService;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Application\CreateSpecialist;
use App\Modules\Specialists\Application\SetSpecialistActive;
use App\Modules\Specialists\Application\UpdateSpecialist;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class MilestoneThreeCrmTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_staff_can_manage_the_full_service_configuration_without_float_money(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $this->enableFeature($organization, OrganizationFeature::ServiceCatalog);
        $this->setOrganization($organization);

        $service = app(CreateService::class)->handle($admin, [
            'name' => 'Fallback catalog item',
            'summary' => 'A managed catalog item.',
            'catalog_type' => CatalogItemType::PhysicalProduct->value,
            'name_ru' => 'Каталожный товар',
            'name_en' => 'Catalog product',
            'description_ru' => 'Описание',
            'description_en' => 'Description',
            'category' => 'Equipment',
            'duration_minutes' => null,
            'buffer_minutes' => 0,
            'formats' => [],
            'price_minor' => '12500',
            'price_currency' => 'THB',
            'payment_policy' => 'manual',
            'is_active' => true,
        ]);

        self::assertSame(CatalogItemType::PhysicalProduct, $service->catalog_type);
        self::assertSame(12500, $service->price_minor);
        self::assertSame('THB', $service->price_currency);

        $updated = app(UpdateService::class)->handle($admin, $service, [
            'name' => 'Fallback service',
            'summary' => 'Updated service.',
            'catalog_type' => CatalogItemType::Service->value,
            'name_ru' => 'Услуга',
            'name_en' => 'Service',
            'description_ru' => 'Описание услуги',
            'description_en' => 'Service description',
            'category' => 'Consultation',
            'duration_minutes' => 90,
            'buffer_minutes' => 15,
            'formats' => ['office', 'online'],
            'price_minor' => 9999,
            'price_currency' => 'USD',
            'payment_policy' => 'prepay',
            'is_active' => false,
        ]);

        self::assertSame(CatalogItemType::Service, $updated->catalog_type);
        self::assertSame(90, $updated->duration_minutes);
        self::assertSame(['office', 'online'], $updated->formats);
        self::assertSame(9999, $updated->price_minor);
        self::assertFalse($updated->is_active);
        self::assertSame(2, DB::table('audit_events')->where('organization_id', $organization->id)
            ->whereIn('action', ['service.created', 'service.updated'])->count());
    }

    public function test_service_catalog_entitlement_is_enforced_by_actions(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);

        $this->expectException(AuthorizationException::class);
        app(CreateService::class)->handle($admin, 'Denied', 'The feature is disabled.');
    }

    public function test_service_actions_reject_cross_organization_records(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $otherService = Service::factory()->forOrganization($otherOrganization)->create();
        $this->enableFeature($organization, OrganizationFeature::ServiceCatalog);
        $this->setOrganization($organization);

        $this->expectException(AuthorizationException::class);
        app(UpdateService::class)->handle($admin, $otherService, 'Cross-org', 'Must fail.');
    }

    public function test_client_crm_edits_do_not_overwrite_verified_identity_and_restrictions_are_audited(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create([
            'email' => 'verified@example.com',
        ]);
        ClientChannelIdentity::factory()->forClient($client)->create([
            'channel' => 'email',
            'external_id' => 'verified@example.com',
            'verification_status' => ChannelIdentityStatus::Verified->value,
        ]);
        $this->enableFeature($organization, OrganizationFeature::ClientRecords);
        $this->setOrganization($organization);

        $this->expectException(ValidationException::class);
        app(UpdateClientProfileFromCrm::class)->handle($admin, $client, [
            'full_name' => 'Updated Client',
            'email' => 'changed@example.com',
        ]);

        $updated = app(UpdateClientProfileFromCrm::class)->handle($admin, $client, [
            'full_name' => 'Updated Client',
            'language' => 'ru',
            'timezone' => 'Asia/Almaty',
        ]);
        self::assertSame('Updated Client', $updated->full_name);
        self::assertSame('verified@example.com', $updated->email);

        $restriction = app(BlockClientSelfBooking::class)->handle($admin, $updated, 'Needs staff review.');
        self::assertSame('Needs staff review.', $restriction->reason);
        self::assertNotNull($updated->fresh()->activeBookingRestriction);

        app(UnblockClientSelfBooking::class)->handle($admin, $updated);
        self::assertNull($updated->fresh()->activeBookingRestriction);
        self::assertSame(3, DB::table('audit_events')->where('organization_id', $organization->id)
            ->whereIn('action', [
                'client.profile.updated',
                'client.self_booking.blocked',
                'client.self_booking.unblocked',
            ])->count());
    }

    public function test_client_actions_reject_cross_organization_records(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        $this->enableFeature($organization, OrganizationFeature::ClientRecords);
        $this->setOrganization($organization);

        $this->expectException(AuthorizationException::class);
        app(UpdateClientProfileFromCrm::class)->handle($admin, $otherClient, ['full_name' => 'No access']);
    }

    public function test_client_crm_rejects_unknown_medical_fields(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $this->enableFeature($organization, OrganizationFeature::ClientRecords);
        $this->setOrganization($organization);

        $this->expectException(InvalidArgumentException::class);
        app(UpdateClientProfileFromCrm::class)->handle($admin, $client, [
            'anamnesis' => 'Must remain out of M3.',
        ]);
    }

    public function test_specialist_is_distinct_from_user_and_uses_active_same_organization_membership(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $this->setOrganization($organization);

        $specialist = app(CreateSpecialist::class)->handle(
            actor: $admin,
            displayName: 'Dr. Future Scheduler',
            isActive: true,
            timezone: 'Asia/Almaty',
            staffUserId: $staff->id,
        );

        self::assertSame($organization->id, $specialist->organization_id);
        self::assertSame($staff->id, $specialist->staff_user_id);
        self::assertSame('Asia/Almaty', $specialist->timezone);
        self::assertNotSame($specialist->getKey(), $staff->getKey());

        $updated = app(SetSpecialistActive::class)->handle($admin, $specialist, false);
        self::assertFalse($updated->is_active);
        app(UpdateSpecialist::class)->handle(
            actor: $admin,
            specialist: $updated,
            displayName: $updated->display_name,
            isActive: true,
            timezone: $updated->timezone,
            staffUserId: null,
        );

        self::assertNull($specialist->fresh()->staff_user_id);
        self::assertSame(5, DB::table('audit_events')->where('organization_id', $organization->id)
            ->whereIn('action', [
                'specialist.created',
                'specialist.linked',
                'specialist.deactivated',
                'specialist.activated',
                'specialist.unlinked',
            ])->count());
    }

    public function test_specialist_rejects_cross_organization_user_links(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $otherStaff = User::factory()->forOrganization($otherOrganization, OrganizationRole::Staff)->create();
        $legacyOnlyUser = User::factory()->create();
        DB::table('users')->where('id', $legacyOnlyUser->id)->update(['organization_id' => $organization->id]);
        $this->setOrganization($organization);

        $this->expectException(AuthorizationException::class);
        app(CreateSpecialist::class)->handle($admin, 'No cross-org link', true, null, $otherStaff->id);
    }

    public function test_specialist_actions_reject_cross_organization_records(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $otherSpecialist = Specialist::factory()->forOrganization($otherOrganization)->create();
        $this->setOrganization($organization);

        $this->expectException(AuthorizationException::class);
        app(SetSpecialistActive::class)->handle($admin, $otherSpecialist, false);
    }

    public function test_specialist_does_not_use_legacy_user_ownership_for_links(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $legacyOnlyUser = User::factory()->create();
        DB::table('users')->where('id', $legacyOnlyUser->id)->update(['organization_id' => $organization->id]);
        $this->setOrganization($organization);

        $this->expectException(AuthorizationException::class);
        app(CreateSpecialist::class)->handle($admin, 'No legacy link', true, null, $legacyOnlyUser->id);
    }

    public function test_specialist_rejects_fixed_offset_timezone(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);

        $this->expectException(InvalidArgumentException::class);
        app(CreateSpecialist::class)->handle($admin, 'Invalid timezone', true, '+05:00');
    }

    public function test_specialist_rejects_invalid_timezone(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);

        $this->expectException(InvalidArgumentException::class);
        app(CreateSpecialist::class)->handle($admin, 'Unknown timezone', true, 'Not/AZone');
    }

    public function test_specialist_role_boundaries_are_enforced(): void
    {
        $organization = Organization::factory()->create();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $this->setOrganization($organization);

        self::assertTrue(Gate::forUser($staff)->allows('viewAny', Specialist::class));
        self::assertFalse(Gate::forUser($staff)->allows('create', Specialist::class));

        $this->expectException(AuthorizationException::class);
        app(CreateSpecialist::class)->handle($staff, 'Staff cannot manage');
    }

    public function test_content_is_organization_scoped_and_drives_portal_sections(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $otherAdmin = User::factory()->forOrganization($otherOrganization)->create();
        $this->setOrganization($organization);

        app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'en',
            'title' => 'About the author',
            'body' => 'Managed CRM content.',
            'media' => ['image' => 'private-reference'],
            'sort_order' => 1,
            'is_visible' => true,
        ]);
        app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Об авторе',
            'body' => 'Контент из CRM.',
            'is_visible' => true,
        ]);
        $this->setOrganization($otherOrganization);
        app(CreateContentSection::class)->handle($otherAdmin, [
            'section_key' => 'author',
            'locale' => 'en',
            'title' => 'Other organization',
            'body' => 'Must not leak.',
            'is_visible' => true,
        ]);
        $this->setOrganization($organization);

        $sections = app(ListPublishedContentSections::class)->handle('author');
        self::assertCount(2, $sections);
        self::assertSame(['en', 'ru'], $sections->pluck('locale')->sort()->values()->all());

        config()->set('tenancy.default_organization_id', $organization->id);
        $this->get(route('portal.section', ['section' => 'author']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Section')
                ->where('content.en.title', 'About the author')
                ->where('content.ru.title', 'Об авторе'));
    }

    public function test_filament_resource_queries_are_organization_scoped(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($otherOrganization)->create();
        $specialist = Specialist::factory()->forOrganization($otherOrganization)->create();
        $this->setOrganization($organization);

        self::assertSame([], ClientResource::getEloquentQuery()->pluck('id')->all());
        self::assertSame([], SpecialistResource::getEloquentQuery()->pluck('id')->all());

        $this->actingAs($admin)
            ->get(route('filament.admin.resources.clients.edit', ['record' => $client]))
            ->assertNotFound();
        $this->actingAs($admin)
            ->get(route('filament.admin.resources.specialists.edit', ['record' => $specialist]))
            ->assertNotFound();
    }

    private function enableFeature(Organization $organization, OrganizationFeature $feature): void
    {
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => $feature->value,
            'enabled' => true,
        ]);
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);
    }
}
