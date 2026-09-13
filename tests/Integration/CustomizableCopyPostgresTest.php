<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\UpdateNotificationTemplate;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use Database\Seeders\ScenarioNotificationSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CustomizableCopyPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgresql_repeated_provisioning_preserves_custom_copy_and_selected_version(): void
    {
        $this->requirePostgres();
        [$organization, $admin] = $this->organizationFixture();
        app(OrganizationContext::class)->set($organization);
        app(ScenarioNotificationSeeder::class)->run();

        $template = $this->template($organization, 'booking-created-crm');
        $custom = app(UpdateNotificationTemplate::class)->handle($admin, $template, [
            'template_key' => $template->template_key,
            'name' => 'Моя запись',
            'locale' => 'ru',
            'purpose' => $template->purpose,
            'is_active' => true,
            'subject' => null,
            'body' => 'Моя запись {{ client.full_name }}.',
            'variables' => ['client.full_name'],
            'delivery_mode' => NotificationMessageMode::Text->value,
            'caption_position' => 'below',
            'media' => null,
        ]);
        $customVersion = $custom->versions()->latest('version')->firstOrFail();
        $rule = ScenarioRule::query()
            ->where('organization_id', $organization->getKey())
            ->where('rule_key', 'booking-created-specialist-database')
            ->firstOrFail();
        $rule->forceFill(['template_version_id' => $customVersion->getKey()])->save();

        app(ScenarioNotificationSeeder::class)->run();
        app(ScenarioNotificationSeeder::class)->run();

        self::assertSame('Моя запись', $custom->fresh()->name);
        self::assertSame('Моя запись {{ client.full_name }}.', $customVersion->fresh()->body);
        self::assertSame($customVersion->getKey(), $rule->fresh()->template_version_id);
        self::assertSame(2, $custom->fresh()->versions()->count());
    }

    public function test_postgresql_forward_upgrade_changes_only_the_exact_legacy_default(): void
    {
        $this->requirePostgres();
        [$organization, $admin] = $this->organizationFixture();
        $legacyBody = 'Новая запись: {{ client.full_name }} — {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.';
        [$template, $legacyVersion, $rule] = $this->legacyBookingTemplate($organization, $legacyBody);

        $this->runBookingCopyMigration();

        $upgraded = $template->fresh();
        $upgradedVersion = $upgraded->versions()->latest('version')->firstOrFail();
        self::assertSame('Новая запись от клиента', $upgraded->name);
        self::assertSame(
            'Клиент {{ client.full_name }} отправил заявку на запись: {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}. Проверьте заявку и подтвердите запись.',
            $upgradedVersion->body,
        );
        self::assertSame($upgradedVersion->getKey(), $rule->fresh()->template_version_id);
        self::assertSame(2, $upgraded->versions()->count());

        $customTemplate = NotificationTemplate::forceCreate([
            'organization_id' => $organization->getKey(),
            'template_key' => 'booking-confirmed-crm',
            'name' => 'Моя подтверждённая запись',
            'locale' => 'ru',
            'purpose' => ScenarioRulePurpose::Transactional->value,
            'is_active' => true,
        ]);
        $customVersion = NotificationTemplateVersion::forceCreate([
            'organization_id' => $organization->getKey(),
            'template_id' => $customTemplate->getKey(),
            'version' => 1,
            'status' => NotificationTemplateStatus::Published->value,
            'subject' => 'Запись подтверждена',
            'body' => 'Мой текст подтверждения {{ client.full_name }}.',
            'variables' => ['client.full_name'],
            'delivery_mode' => NotificationMessageMode::Text->value,
            'caption_position' => 'below',
            'media' => null,
            'created_by_user_id' => $admin->getKey(),
            'published_at' => now(),
        ]);
        $customRule = ScenarioRule::forceCreate([
            'organization_id' => $organization->getKey(),
            'rule_key' => 'booking-confirmed-specialist-database',
            'name' => 'Моё правило',
            'trigger_event' => ScenarioEventType::BookingConfirmed->value,
            'is_enabled' => true,
            'system_managed' => false,
            'delay_value' => 0,
            'delay_unit' => 'minutes',
            'purpose' => ScenarioRulePurpose::Transactional->value,
            'conditions' => [],
            'recipient_strategy' => ['type' => 'assigned_specialist'],
            'channel_priority' => ['database'],
            'template_version_id' => $customVersion->getKey(),
            'max_occurrences' => 1,
            'repeat_interval_value' => null,
            'repeat_interval_unit' => null,
            'version' => 1,
        ]);

        $this->runBookingCopyMigration();

        self::assertNotSame($legacyVersion->getKey(), $rule->fresh()->template_version_id);
        self::assertSame('Моя подтверждённая запись', $customTemplate->fresh()->name);
        self::assertSame($customVersion->getKey(), $customRule->fresh()->template_version_id);
        self::assertSame('Мой текст подтверждения {{ client.full_name }}.', $customVersion->fresh()->body);
        self::assertSame(1, $customTemplate->fresh()->versions()->count());
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationFixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();

        return [$organization, $admin];
    }

    private function template(Organization $organization, string $key): NotificationTemplate
    {
        return NotificationTemplate::query()
            ->where('organization_id', $organization->getKey())
            ->where('template_key', $key)
            ->where('locale', 'ru')
            ->firstOrFail();
    }

    /** @return array{0: NotificationTemplate, 1: NotificationTemplateVersion, 2: ScenarioRule} */
    private function legacyBookingTemplate(Organization $organization, string $body): array
    {
        $template = NotificationTemplate::forceCreate([
            'organization_id' => $organization->getKey(),
            'template_key' => 'booking-created-crm',
            'name' => 'Новая запись в CRM',
            'locale' => 'ru',
            'purpose' => ScenarioRulePurpose::Transactional->value,
            'is_active' => true,
        ]);
        $version = NotificationTemplateVersion::forceCreate([
            'organization_id' => $organization->getKey(),
            'template_id' => $template->getKey(),
            'version' => 1,
            'status' => NotificationTemplateStatus::Published->value,
            'subject' => 'Новая запись',
            'body' => $body,
            'variables' => ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time'],
            'delivery_mode' => NotificationMessageMode::Text->value,
            'caption_position' => 'below',
            'media' => null,
            'published_at' => now(),
        ]);
        $rule = ScenarioRule::forceCreate([
            'organization_id' => $organization->getKey(),
            'rule_key' => 'booking-created-specialist-database',
            'name' => 'Новая запись',
            'trigger_event' => ScenarioEventType::BookingCreated->value,
            'is_enabled' => true,
            'system_managed' => false,
            'delay_value' => 0,
            'delay_unit' => 'minutes',
            'purpose' => ScenarioRulePurpose::Transactional->value,
            'conditions' => [],
            'recipient_strategy' => ['type' => 'assigned_specialist'],
            'channel_priority' => ['database'],
            'template_version_id' => $version->getKey(),
            'max_occurrences' => 1,
            'repeat_interval_value' => null,
            'repeat_interval_unit' => null,
            'version' => 1,
        ]);

        return [$template, $version, $rule];
    }

    private function runBookingCopyMigration(): void
    {
        $migration = require database_path('migrations/2026_09_13_100000_upgrade_untouched_booking_notification_defaults.php');
        $migration->up();
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Customizable copy PostgreSQL coverage requires the isolated PostgreSQL test environment.');
        }
    }
}
