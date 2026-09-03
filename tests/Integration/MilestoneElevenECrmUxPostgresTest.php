<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Broadcasts\Application\CreateBroadcastCampaign;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Content\Domain\Enums\ContentDeliveryMode;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Identity\Application\AuthenticateClientWithVerifiedChannel;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Application\CreatePlatformLegalDocumentDraft;
use App\Modules\Identity\Application\ListPublishedLegalDocuments;
use App\Modules\Identity\Application\PublishLegalDocument;
use App\Modules\Identity\Application\RecordPortalClientConsents;
use App\Modules\Identity\Application\UpdateClientProfileFromCrm;
use App\Modules\Identity\Application\VerifiedChannelIdentity;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\ListClientBookings;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

final class MilestoneElevenECrmUxPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgresql_legal_consent_history_keeps_the_accepted_published_version(): void
    {
        $this->requirePostgres();
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create(['language' => 'en']);
        $v1 = $this->publishLegalDocument($organization, '2026-09-03-v1');

        app(RecordPortalClientConsents::class)->handle($client, [[
            'legal_document_id' => $v1->getKey(),
            'granted' => true,
        ]]);
        $v2 = $this->publishLegalDocument($organization, '2026-09-03-v2');

        $consent = ClientConsent::query()->sole();

        self::assertSame($v1->getKey(), $consent->legal_document_id);
        self::assertSame('2026-09-03-v1', $consent->version);
        self::assertSame('published', $v2->refresh()->status->value);
        self::assertSame('archived', $v1->refresh()->status->value);
        self::assertSame(1, ClientConsent::query()->where('granted', true)->count());

        $v1->content = 'Changed archived text.';
        $this->expectException(LogicException::class);
        $v1->save();
    }

    public function test_postgresql_username_and_telegram_id_search_is_case_insensitive_and_tenant_scoped(): void
    {
        $this->requirePostgres();
        $organization = $this->organizationWithClientRecords();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);
        $client = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Иван Петров',
            'phone' => '+7 (999) 123-45-67',
        ]);
        $aikhana = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Aikhana',
        ]);
        $foreign = Client::factory()->forOrganization($otherOrganization)->create();

        ClientChannelIdentity::factory()->forClient($client)->create([
            'channel' => 'telegram',
            'external_id' => '806750628',
            'external_username' => 'Aikhia',
            'verification_status' => ChannelIdentityStatus::Verified,
        ]);
        ClientChannelIdentity::factory()->forClient($foreign)->create([
            'channel' => 'telegram',
            'external_id' => '806750628',
            'external_username' => 'Aikhia',
            'verification_status' => ChannelIdentityStatus::Verified,
        ]);
        ClientChannelIdentity::factory()->forClient($aikhana)->create([
            'channel' => 'telegram',
            'external_id' => '806750629',
            'external_username' => 'aikhana',
            'verification_status' => ChannelIdentityStatus::Verified,
        ]);

        $search = app(ClientSearch::class);

        self::assertSame([$client->getKey()], $search->query($admin, 'Петров')->pluck('id')->all());
        self::assertSame([$client->getKey()], $search->query($admin, '8 999 123 45 67')->pluck('id')->all());
        self::assertSame([$client->getKey()], $search->query($admin, 'Aikhia')->pluck('id')->all());
        self::assertSame([$client->getKey()], $search->query($admin, '@aIkHiA')->pluck('id')->all());
        self::assertSame([$aikhana->getKey()], $search->query($admin, 'AIKHANA')->pluck('id')->all());
        self::assertSame([$client->getKey()], $search->query($admin, '806750628')->pluck('id')->all());
        self::assertNotContains($foreign->getKey(), $search->query($admin, 'Aikhia')->pluck('id')->all());
    }

    public function test_postgresql_persists_new_broadcast_content_and_timezone_contract_fields(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create(['timezone' => 'Asia/Almaty']);
        $admin = User::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);
        $client = Client::factory()->forOrganization($organization)->create([
            'timezone' => 'Asia/Almaty',
            'timezone_source' => 'device',
        ]);

        $campaign = new BroadcastCampaign;
        $campaign->forceFill([
            'organization_id' => $organization->getKey(),
            'created_by_user_id' => $admin->getKey(),
            'name' => 'PostgreSQL media campaign',
            'state' => BroadcastCampaignState::Draft,
            'send_mode' => 'immediate',
            'audience_type' => 'all',
            'channel_priority' => ['telegram'],
            'segment_definition' => [],
            'selected_client_ids' => [],
            'message_mode' => 'compose',
            'message_body' => '<p>😀</p>',
            'delivery_mode' => 'image_caption',
            'caption_position' => 'above',
            'media' => ['image' => 'https://cdn.example.test/image.jpg'],
            'segment_summary' => 'All eligible clients',
        ])->save();
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'en',
            'delivery_mode' => ContentDeliveryMode::Both,
        ]);

        $storedCampaign = $campaign->refresh();
        self::assertSame(['image' => 'https://cdn.example.test/image.jpg'], $storedCampaign->media);
        self::assertSame('image_caption', $storedCampaign->delivery_mode);
        self::assertSame('above', $storedCampaign->caption_position);
        self::assertSame(ContentDeliveryMode::Both, $section->refresh()->delivery_mode);
        self::assertSame('device', $client->refresh()->timezone_source);

        $local = CarbonImmutable::createSafe(2026, 9, 3, 11, 0, 0, 'Asia/Almaty');
        self::assertSame('2026-09-03T06:00:00+00:00', $local->utc()->toIso8601String());

        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => $local->utc(),
                'ends_at' => $local->utc()->addHour(),
                'blocking_ends_at' => $local->utc()->addHour()->addMinutes(15),
            ]);
        app(ClientPortalContext::class)->set($client);

        $projection = app(ListClientBookings::class)->projection($booking->fresh(['service', 'specialist']), 'en');

        self::assertSame('2026-09-03T06:00:00+00:00', $projection['startsAt']);
        self::assertSame('2026-09-03', $projection['localDate']);
        self::assertSame('11:00', $projection['localTime']);
        self::assertSame('+05:00', $projection['displayUtcOffset']);
    }

    public function test_postgresql_new_delivery_and_timezone_constraints_reject_invalid_values(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create();

        try {
            DB::transaction(function () use ($organization): void {
                DB::table('content_sections')->insert([
                    'organization_id' => $organization->getKey(),
                    'section_key' => 'invalid',
                    'locale' => 'en',
                    'title' => 'Invalid',
                    'body' => 'Invalid',
                    'delivery_mode' => 'web',
                    'sort_order' => 0,
                    'is_visible' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
            self::fail('The PostgreSQL content delivery check accepted an invalid mode.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $client = Client::factory()->forOrganization($organization)->create();
        $client->timezone_source = 'browser';

        $this->expectException(QueryException::class);
        $client->save();
    }

    public function test_postgresql_broadcast_boundary_rejects_rendered_text_over_telegram_limit(): void
    {
        $this->requirePostgres();
        $organization = $this->organizationWithClientRecords();
        $admin = User::factory()->forOrganization($organization)->create();

        $this->expectException(ValidationException::class);
        app(CreateBroadcastCampaign::class)->handle($admin, [
            'name' => 'PostgreSQL rendered limit',
            'send_mode' => 'immediate',
            'audience_type' => 'all',
            'selected_client_ids' => [],
            'channel_priority' => ['telegram'],
            'segment_definition' => [],
            'message_mode' => 'compose',
            'message_body' => str_repeat('a', 3900).' {{ client.full_name }}',
            'delivery_mode' => 'text',
            'caption_position' => 'below',
            'scheduled_at' => null,
        ]);
    }

    public function test_postgresql_client_timezone_is_set_from_the_first_device_session_only(): void
    {
        $this->requirePostgres();
        $organization = $this->organizationWithClientRecords();

        $identity = new VerifiedChannelIdentity(
            channel: 'telegram',
            externalId: '993001',
            displayName: 'Timezone client',
            language: 'ru',
            username: 'timezone_client',
        );
        $client = app(AuthenticateClientWithVerifiedChannel::class)->handle(
            verifiedIdentity: $identity,
            clientTimezone: 'Europe/Berlin',
        );

        self::assertSame('Europe/Berlin', $client->timezone);
        self::assertSame('device', $client->timezone_source);

        app(AuthenticateClientWithVerifiedChannel::class)->handle(
            verifiedIdentity: $identity,
            clientTimezone: 'America/New_York',
        );

        self::assertSame('Europe/Berlin', $client->refresh()->timezone);
        self::assertSame('device', $client->timezone_source);
    }

    public function test_postgresql_crm_save_does_not_promote_an_unchanged_device_timezone_to_manual(): void
    {
        $this->requirePostgres();
        $organization = $this->organizationWithClientRecords();
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create([
            'timezone' => 'Europe/Berlin',
            'timezone_source' => 'device',
        ]);

        app(UpdateClientProfileFromCrm::class)->handle($admin, $client, [
            'full_name' => 'Updated CRM client',
            'timezone' => 'Europe/Berlin',
        ]);

        self::assertSame('device', $client->refresh()->timezone_source);
    }

    public function test_postgresql_migrates_the_legacy_chuklov_timezone_without_touching_other_organizations(): void
    {
        $this->requirePostgres();
        $legacyId = DB::table('organizations')->insertGetId([
            'name' => 'Chuklov',
            'slug' => 'chuklov',
            'timezone' => 'Asia/Bangkok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherOrganization = Organization::factory()->create(['timezone' => 'Asia/Bangkok']);

        $migration = require database_path('migrations/2026_09_03_003834_set_chuklov_timezone_to_almaty.php');
        $migration->up();

        self::assertSame(
            'Asia/Almaty',
            DB::table('organizations')->where('id', $legacyId)->value('timezone'),
        );
        self::assertSame('Asia/Bangkok', $otherOrganization->refresh()->timezone);
    }

    public function test_postgresql_legal_documents_fallback_to_the_available_supported_locale(): void
    {
        $this->requirePostgres();
        $organization = $this->organizationWithClientRecords();
        $document = $this->publishLegalDocument($organization, '2026-09-03-ru-only', 'ru');

        $documents = app(ListPublishedLegalDocuments::class)->handle('en');

        self::assertSame([$document->getKey()], $documents->pluck('id')->all());
        self::assertSame('ru', $documents->sole()->locale);
    }

    private function publishLegalDocument(Organization $organization, string $version, string $locale = 'en'): LegalDocument
    {
        return app(PublishLegalDocument::class)->handle(
            app(CreatePlatformLegalDocumentDraft::class)->handle(
                organization: $organization,
                documentType: 'privacy',
                purpose: 'privacy_consent',
                locale: $locale,
                version: $version,
                content: 'Configured privacy text '.$version.'.',
                isRequired: true,
            ),
        );
    }

    private function organizationWithClientRecords(): Organization
    {
        $organization = Organization::factory()->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        app(OrganizationContext::class)->set($organization);

        return $organization;
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL is required for CRM UX persistence coverage.');
        }
    }
}
