<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\AcquisitionAnalytics;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Attribution\Application\AttributionSourceCatalog;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AttributionSourceCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_mapping_is_used_by_acquisition_analytics_without_changing_attribution_records(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create(['created_at' => '2026-10-28 12:00:00']);
        $attribution = new ClientAttribution;
        $attribution->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'source_type' => 'source',
            'source' => 'instagram_ads',
            'capture_channel' => 'portal',
            'capture_context' => 'test',
            'captured_at' => now(),
            'accepted_at' => now(),
        ])->save();
        app(OrganizationContext::class)->set($organization);

        app(AttributionSourceCatalog::class)->save($admin, [[
            'source_key' => 'social_media',
            'label' => 'Социальные сети',
            'aliases' => [['value' => 'instagram_ads']],
            'is_active' => true,
            'sort_order' => 1,
        ]]);

        $analytics = app(AcquisitionAnalytics::class)->handle(
            $admin,
            DashboardPeriod::fromFilters(
                ['period' => DashboardPeriod::Custom, 'start_date' => '2000-01-01', 'end_date' => '2100-01-01'],
                'UTC',
                CarbonImmutable::parse('2026-10-28 12:00:00', 'UTC'),
            ),
        );

        self::assertSame(1, $analytics->newClients);
        self::assertSame('Социальные сети', $analytics->sources[0]->label);
        self::assertSame('instagram_ads', ClientAttribution::query()->firstOrFail()->source);
    }

    public function test_catalog_is_tenant_scoped_and_rejects_duplicate_or_invalid_keys(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $foreignOrganization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);

        app(AttributionSourceCatalog::class)->save($admin, [[
            'source_key' => 'recommendation',
            'label' => 'Рекомендации',
            'aliases' => [],
            'is_active' => true,
            'sort_order' => 0,
        ]]);

        self::assertCount(1, app(AttributionSourceCatalog::class)->rows($organization->getKey()));
        self::assertSame([], app(AttributionSourceCatalog::class)->rows($foreignOrganization->getKey()));

        $this->expectException(ValidationException::class);
        app(AttributionSourceCatalog::class)->save($admin, [
            ['source_key' => 'bad key', 'label' => 'Ошибка', 'aliases' => []],
            ['source_key' => 'bad key', 'label' => 'Дубликат', 'aliases' => []],
        ]);
    }

    public function test_selected_source_filters_new_clients_and_buckets_by_owner_mapping(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $socialClient = Client::factory()->forOrganization($organization)->create(['created_at' => '2026-10-28 12:00:00']);
        $telegramClient = Client::factory()->forOrganization($organization)->create(['created_at' => '2026-10-28 12:00:00']);
        $attribution = new ClientAttribution;
        $attribution->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $socialClient->getKey(),
            'source_type' => 'source',
            'source' => 'instagram_ads',
            'capture_channel' => 'portal',
            'capture_context' => 'test',
            'captured_at' => now(),
            'accepted_at' => now(),
        ])->save();
        $attribution = new ClientAttribution;
        $attribution->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $telegramClient->getKey(),
            'source_type' => 'source',
            'source' => 'telegram',
            'capture_channel' => 'portal',
            'capture_context' => 'test',
            'captured_at' => now(),
            'accepted_at' => now(),
        ])->save();
        app(OrganizationContext::class)->set($organization);

        app(AttributionSourceCatalog::class)->save($admin, [
            [
                'source_key' => 'social_media',
                'label' => 'Социальные сети',
                'aliases' => [['value' => 'instagram_ads']],
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'source_key' => 'telegram',
                'label' => 'Telegram',
                'aliases' => [],
                'is_active' => true,
                'sort_order' => 2,
            ],
        ]);

        $analytics = app(AcquisitionAnalytics::class)->handle(
            $admin,
            DashboardPeriod::fromFilters(
                ['period' => DashboardPeriod::Custom, 'start_date' => '2000-01-01', 'end_date' => '2100-01-01'],
                'UTC',
                CarbonImmutable::parse('2026-10-28 12:00:00', 'UTC'),
            ),
            sourceKey: 'social_media',
        );

        self::assertSame(1, $analytics->newClients);
        self::assertCount(1, $analytics->sources);
        self::assertSame('Социальные сети', $analytics->sources[0]->label);
        self::assertSame(1, $analytics->sources[0]->count);
    }

    public function test_new_owner_mapping_gets_a_hidden_stable_key_without_developer_input(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);

        app(AttributionSourceCatalog::class)->save($admin, [[
            'label' => 'Новый канал',
            'aliases' => [['value' => 'new_channel']],
            'is_active' => true,
            'sort_order' => 0,
        ]]);

        $mapping = app(AttributionSourceCatalog::class)->rows($organization->getKey())[0];
        self::assertMatchesRegularExpression('/^source_[a-z0-9]{12}$/', $mapping['source_key']);
        self::assertSame('Новый канал', $mapping['label']);
    }

    public function test_default_catalog_is_provisioned_once_and_owner_changes_are_preserved(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);

        $catalog = app(AttributionSourceCatalog::class);
        $catalog->ensureDefaults($admin);
        self::assertSame([
            'Рекомендация знакомых',
            'Telegram-канал Чуклова',
            'Instagram',
            'Поиск Yandex/Google',
            'Сайт chuklov.com',
            'Другое',
        ], array_column($catalog->rows($organization->getKey()), 'label'));

        $catalog->save($admin, [[
            'source_key' => 'instagram',
            'label' => 'Instagram и Reels',
            'aliases' => [['value' => 'instagram']],
            'is_active' => true,
            'sort_order' => 0,
        ]]);
        $catalog->ensureDefaults($admin);

        self::assertSame('Instagram и Reels', $catalog->rows($organization->getKey())[0]['label']);
        self::assertCount(6, $catalog->rows($organization->getKey()));
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();

        return [$organization, $admin];
    }
}
