<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\EnsureAppointmentReminderDefaults;
use App\Modules\Scenarios\Application\UpdateNotificationTemplate;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use Database\Seeders\LegalDocumentSeeder;
use Database\Seeders\ScenarioNotificationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CustomizableCopySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_platform_provisioning_uses_the_current_booking_defaults(): void
    {
        [$organization] = $this->organizationFixture();

        app(ScenarioNotificationSeeder::class)->run();

        self::assertSame(
            'Заявка на запись',
            $this->template($organization, 'booking-created')->name,
        );
        self::assertSame(
            "Заявка на запись принята\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}\nМы скоро подтвердим запись.",
            $this->latestVersion($this->template($organization, 'booking-created'))->body,
        );
        self::assertSame(
            "Ваша запись подтверждена\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}",
            $this->latestVersion($this->template($organization, 'booking-confirmed'))->body,
        );
        self::assertSame(
            "Новая запись от клиента\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}\nTelegram клиента: {{ client.telegram_contact }}.",
            $this->latestVersion($this->template($organization, 'booking-created-specialist'))->body,
        );
        self::assertSame(
            "Запись клиента подтверждена\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}\nTelegram клиента: {{ client.telegram_contact }}.",
            $this->latestVersion($this->template($organization, 'booking-confirmed-specialist'))->body,
        );
        self::assertSame(
            'Клиент {{ client.full_name }} отправил заявку на запись: {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}. Проверьте заявку и подтвердите запись.',
            $this->latestVersion($this->template($organization, 'booking-created-crm'))->body,
        );
    }

    public function test_repeated_provisioning_preserves_custom_copy_and_selected_versions(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        app(ScenarioNotificationSeeder::class)->run();

        $cases = [
            [
                'template_key' => 'booking-created',
                'rule_key' => 'booking-created-client-ru',
                'name' => 'Мой текст для клиента',
                'body' => 'Мой текст для записи {{ booking.specialist_name }}.',
                'variables' => ['booking.specialist_name'],
            ],
            [
                'template_key' => 'booking-created-crm',
                'rule_key' => 'booking-created-specialist-database',
                'name' => 'Моя карточка записи',
                'body' => 'Моя карточка: {{ client.full_name }}.',
                'variables' => ['client.full_name'],
            ],
            [
                'template_key' => 'appointment-reminder-client-office',
                'rule_key' => 'appointment-reminder-client-office',
                'name' => 'Моё напоминание',
                'body' => 'Моё напоминание для {{ booking.specialist_name }}.',
                'variables' => ['booking.specialist_name'],
            ],
        ];
        $snapshots = [];

        foreach ($cases as $case) {
            $template = $this->template($organization, $case['template_key']);
            $custom = $this->customizeTemplate($admin, $template, $case['name'], $case['body'], $case['variables']);
            $version = $this->latestVersion($custom);
            $rule = ScenarioRule::query()
                ->where('organization_id', $organization->getKey())
                ->where('rule_key', $case['rule_key'])
                ->firstOrFail();
            $rule->forceFill(['template_version_id' => $version->getKey()])->save();

            $snapshots[$case['template_key']] = [
                'name' => $custom->name,
                'body' => $version->body,
                'version_id' => $version->getKey(),
                'rule_version_id' => $version->getKey(),
            ];
        }

        app(ScenarioNotificationSeeder::class)->run();
        app(ScenarioNotificationSeeder::class)->run();

        foreach ($cases as $case) {
            $template = $this->template($organization, $case['template_key']);
            $version = $this->latestVersion($template);
            $rule = ScenarioRule::query()
                ->where('organization_id', $organization->getKey())
                ->where('rule_key', $case['rule_key'])
                ->firstOrFail();

            self::assertSame($snapshots[$case['template_key']]['name'], $template->name);
            self::assertSame($snapshots[$case['template_key']]['body'], $version->body);
            self::assertSame($snapshots[$case['template_key']]['version_id'], $version->getKey());
            self::assertSame($snapshots[$case['template_key']]['rule_version_id'], $rule->template_version_id);
        }
    }

    public function test_missing_rule_uses_an_existing_custom_version_instead_of_version_one(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        app(ScenarioNotificationSeeder::class)->run();

        $template = $this->template($organization, 'booking-created');
        $custom = $this->customizeTemplate(
            admin: $admin,
            template: $template,
            name: 'Мой текст для клиента',
            body: 'Мой текст для {{ booking.specialist_name }}.',
            variables: ['booking.specialist_name'],
        );
        $customVersion = $this->latestVersion($custom);
        ScenarioRule::query()
            ->where('organization_id', $organization->getKey())
            ->where('rule_key', 'booking-created-client-ru')
            ->delete();

        app(ScenarioNotificationSeeder::class)->run();

        self::assertSame(
            $customVersion->getKey(),
            ScenarioRule::query()
                ->where('organization_id', $organization->getKey())
                ->where('rule_key', 'booking-created-client-ru')
                ->value('template_version_id'),
        );

        $reminderTemplate = $this->template($organization, 'appointment-reminder-client-office');
        $customReminder = $this->customizeTemplate(
            admin: $admin,
            template: $reminderTemplate,
            name: 'Моё напоминание',
            body: 'Моё напоминание для {{ booking.specialist_name }}.',
            variables: ['booking.specialist_name'],
        );
        $customReminderVersion = $this->latestVersion($customReminder);
        ScenarioRule::query()
            ->where('organization_id', $organization->getKey())
            ->where('rule_key', 'appointment-reminder-client-office')
            ->delete();

        app(EnsureAppointmentReminderDefaults::class)->handle($organization);

        self::assertSame(
            $customReminderVersion->getKey(),
            ScenarioRule::query()
                ->where('organization_id', $organization->getKey())
                ->where('rule_key', 'appointment-reminder-client-office')
                ->value('template_version_id'),
        );
    }

    public function test_legal_and_content_copy_survive_default_seeders(): void
    {
        [$organization] = $this->organizationFixture();
        $legal = LegalDocument::factory()->forOrganization($organization)->published()->create([
            'document_type' => 'offer',
            'purpose' => 'offer_consent',
            'locale' => 'ru',
            'content' => 'Утверждённый текст Чуклова.',
        ]);
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'about_us',
            'locale' => 'ru',
            'title' => 'Мой раздел',
            'body' => 'Мой текст раздела.',
        ]);

        app(LegalDocumentSeeder::class)->run();
        app(ScenarioNotificationSeeder::class)->run();

        self::assertSame('Утверждённый текст Чуклова.', $legal->fresh()->content);
        self::assertSame('Мой раздел', $section->fresh()->title);
        self::assertSame('Мой текст раздела.', $section->fresh()->body);
    }

    public function test_legacy_untouched_booking_default_is_upgraded_once(): void
    {
        [$organization] = $this->organizationFixture();
        [$template, $version, $rule] = $this->legacyBookingTemplate(
            organization: $organization,
            templateKey: 'booking-created-crm',
            ruleKey: 'booking-created-specialist-database',
            name: 'Новая запись в CRM',
            subject: 'Новая запись',
            body: 'Новая запись: {{ client.full_name }} — {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.',
            variables: ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time'],
            event: ScenarioEventType::BookingCreated,
        );

        $this->runBookingCopyMigration();

        $upgraded = $template->fresh();
        $upgradedVersion = $this->latestVersion($upgraded);
        $upgradedRule = $rule->fresh();

        self::assertSame('Новая запись от клиента', $upgraded->name);
        self::assertSame(
            'Клиент {{ client.full_name }} отправил заявку на запись: {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}. Проверьте заявку и подтвердите запись.',
            $upgradedVersion->body,
        );
        self::assertNotSame($version->getKey(), $upgradedVersion->getKey());
        self::assertSame($upgradedVersion->getKey(), $upgradedRule->template_version_id);
        self::assertSame(2, $upgraded->versions()->count());

        $this->runBookingCopyMigration();

        self::assertSame(2, $upgraded->fresh()->versions()->count());
        self::assertSame($upgradedVersion->getKey(), $rule->fresh()->template_version_id);
    }

    public function test_custom_template_name_skips_legacy_upgrade(): void
    {
        [$organization] = $this->organizationFixture();
        [$template, $version, $rule] = $this->legacyBookingTemplate(
            organization: $organization,
            templateKey: 'booking-created-crm',
            ruleKey: 'booking-created-specialist-database',
            name: 'Моя новая запись',
            subject: 'Новая запись',
            body: 'Новая запись: {{ client.full_name }} — {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.',
            variables: ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time'],
            event: ScenarioEventType::BookingCreated,
        );

        $this->runBookingCopyMigration();

        self::assertSame('Моя новая запись', $template->fresh()->name);
        self::assertSame($version->getKey(), $this->latestVersion($template->fresh())->getKey());
        self::assertSame($version->getKey(), $rule->fresh()->template_version_id);
    }

    public function test_custom_template_body_skips_legacy_upgrade(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        [$template, $version, $rule] = $this->legacyBookingTemplate(
            organization: $organization,
            templateKey: 'booking-created-crm',
            ruleKey: 'booking-created-specialist-database',
            name: 'Новая запись в CRM',
            subject: 'Новая запись',
            body: 'Мой сохранённый текст {{ client.full_name }}.',
            variables: ['client.full_name'],
            event: ScenarioEventType::BookingCreated,
            createdByUserId: $admin->getKey(),
        );

        $this->runBookingCopyMigration();

        self::assertSame('Мой сохранённый текст {{ client.full_name }}.', $version->fresh()->body);
        self::assertSame($version->getKey(), $rule->fresh()->template_version_id);
        self::assertSame(1, $template->fresh()->versions()->count());
    }

    public function test_custom_template_composition_skips_legacy_upgrade(): void
    {
        [$organization] = $this->organizationFixture();
        [$template, $version, $rule] = $this->legacyBookingTemplate(
            organization: $organization,
            templateKey: 'booking-created-crm',
            ruleKey: 'booking-created-specialist-database',
            name: 'Новая запись в CRM',
            subject: 'Новая запись',
            body: 'Новая запись: {{ client.full_name }} — {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.',
            variables: ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time'],
            event: ScenarioEventType::BookingCreated,
            deliveryMode: NotificationMessageMode::ImageWithCaption,
            captionPosition: 'above',
            media: ['image' => 'organizations/custom/booking.jpg', 'alt' => 'Моя картинка'],
        );

        $this->runBookingCopyMigration();

        self::assertSame($version->getKey(), $rule->fresh()->template_version_id);
        self::assertSame($version->getKey(), $this->latestVersion($template->fresh())->getKey());
        self::assertSame(NotificationMessageMode::ImageWithCaption, $version->fresh()->delivery_mode);
        self::assertSame('above', $version->fresh()->caption_position);
        self::assertSame(['image' => 'organizations/custom/booking.jpg', 'alt' => 'Моя картинка'], $version->fresh()->media);
        self::assertSame(1, $template->fresh()->versions()->count());
    }

    public function test_custom_active_template_version_remains_selected(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        [$template, $legacyVersion, $rule] = $this->legacyBookingTemplate(
            organization: $organization,
            templateKey: 'booking-created-crm',
            ruleKey: 'booking-created-specialist-database',
            name: 'Новая запись в CRM',
            subject: 'Новая запись',
            body: 'Новая запись: {{ client.full_name }} — {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.',
            variables: ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time'],
            event: ScenarioEventType::BookingCreated,
        );
        $customVersion = NotificationTemplateVersion::factory()
            ->forTemplate($template)
            ->createdBy($admin)
            ->create([
                'version' => 2,
                'body' => 'Выбранный текст {{ client.full_name }}.',
                'variables' => ['client.full_name'],
            ]);
        $rule->forceFill(['template_version_id' => $customVersion->getKey()])->save();

        $this->runBookingCopyMigration();

        self::assertSame($customVersion->getKey(), $rule->fresh()->template_version_id);
        self::assertSame($customVersion->getKey(), $this->latestVersion($template->fresh())->getKey());
        self::assertSame('Выбранный текст {{ client.full_name }}.', $customVersion->fresh()->body);
        self::assertSame(2, $template->fresh()->versions()->count());
        self::assertNotSame($legacyVersion->getKey(), $rule->fresh()->template_version_id);
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationFixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

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

    private function latestVersion(NotificationTemplate $template): NotificationTemplateVersion
    {
        return $template->versions()->latest('version')->firstOrFail();
    }

    /** @param list<string> $variables */
    private function customizeTemplate(
        User $admin,
        NotificationTemplate $template,
        string $name,
        string $body,
        array $variables,
    ): NotificationTemplate {
        return app(UpdateNotificationTemplate::class)->handle($admin, $template, [
            'template_key' => $template->template_key,
            'name' => $name,
            'locale' => $template->locale,
            'purpose' => $template->purpose,
            'is_active' => $template->is_active,
            'subject' => null,
            'body' => $body,
            'variables' => $variables,
            'delivery_mode' => NotificationMessageMode::Text->value,
            'caption_position' => 'below',
            'media' => null,
        ]);
    }

    /**
     * @param  list<string>  $variables
     * @return array{0: NotificationTemplate, 1: NotificationTemplateVersion, 2: ScenarioRule}
     */
    private function legacyBookingTemplate(
        Organization $organization,
        string $templateKey,
        string $ruleKey,
        string $name,
        ?string $subject,
        string $body,
        array $variables,
        ScenarioEventType $event,
        ?int $createdByUserId = null,
        NotificationMessageMode $deliveryMode = NotificationMessageMode::Text,
        string $captionPosition = 'below',
        ?array $media = null,
    ): array {
        $template = NotificationTemplate::forceCreate([
            'organization_id' => $organization->getKey(),
            'template_key' => $templateKey,
            'name' => $name,
            'locale' => 'ru',
            'purpose' => ScenarioRulePurpose::Transactional->value,
            'is_active' => true,
        ]);
        $version = NotificationTemplateVersion::forceCreate([
            'organization_id' => $organization->getKey(),
            'template_id' => $template->getKey(),
            'version' => 1,
            'status' => NotificationTemplateStatus::Published->value,
            'subject' => $subject,
            'body' => $body,
            'variables' => $variables,
            'delivery_mode' => $deliveryMode->value,
            'caption_position' => $captionPosition,
            'media' => $media,
            'created_by_user_id' => $createdByUserId,
            'published_at' => now(),
        ]);
        $rule = ScenarioRule::forceCreate([
            'organization_id' => $organization->getKey(),
            'rule_key' => $ruleKey,
            'name' => 'Тестовое правило',
            'trigger_event' => $event->value,
            'is_enabled' => true,
            'system_managed' => false,
            'delay_value' => 0,
            'delay_unit' => 'minutes',
            'purpose' => ScenarioRulePurpose::Transactional->value,
            'conditions' => [],
            'recipient_strategy' => ['type' => 'client'],
            'channel_priority' => ['telegram'],
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
}
