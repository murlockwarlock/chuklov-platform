<?php

namespace Tests\Feature;

use App\Filament\Pages\NotificationCatalog;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\ScenarioActions\Pages\ViewScenarioAction;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\EnsureOperationalNotificationDefaults;
use App\Modules\Scenarios\Application\ScenarioContextFactory;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioDeliveryStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioDelivery;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRecipient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class NotificationCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_exposes_event_recipient_channel_template_and_delivery_columns(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(NotificationCatalog::class)
            ->assertSuccessful()
            ->assertSee('Клиент запросил специалиста')
            ->assertSee('Сообщение клиента требует внимания')
            ->assertSee('Сотрудники с правом обработки обращений')
            ->assertSee('CRM')
            ->assertSee('Telegram')
            ->assertSee('Запрос специалиста из AI-компаньона')
            ->assertSee('Включено')
            ->assertSee('Переход по реферальной ссылке')
            ->assertSee('Выключено')
            ->assertSee('Отправок пока нет');
    }

    public function test_safety_attention_notification_is_truthful_and_does_not_claim_the_client_requested_a_specialist(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => 'Анна Клиент']);
        $event = ScenarioEvent::factory()->forOrganization($organization)->create([
            'event_name' => ScenarioEventType::CompanionSpecialistAttention->value,
            'payload' => [
                'client_id' => (int) $client->getKey(),
                'reason' => 'urgent_safety_concern',
            ],
        ]);

        $contextFactory = app(ScenarioContextFactory::class);
        $rendered = $contextFactory->renderContext(
            $contextFactory->evaluationContext($event),
            new ScenarioRecipient('internal', null, null, 'ru'),
        );

        self::assertSame('Сообщение клиента Анна Клиент требует внимания', $rendered['companion']['notification_title']);
        self::assertStringContainsString('AI отметил сообщение клиента как требующее внимания специалиста', $rendered['companion']['notification_body']);
        self::assertStringNotContainsString('запросил специалиста', $rendered['companion']['notification_body']);
    }

    public function test_operational_ai_failure_notification_shows_safe_diagnostics_and_monitoring_link(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => 'Анна Клиент']);
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);
        $rule = ScenarioRule::query()
            ->where('organization_id', $organization->getKey())
            ->where('rule_key', 'companion-fallback-failed-database')
            ->sole();
        $template = $rule->templateVersion;
        $event = ScenarioEvent::factory()->forOrganization($organization)->create([
            'event_name' => ScenarioEventType::CompanionFallbackFailed->value,
            'payload' => [
                'client_id' => (int) $client->getKey(),
                'turn_id' => 42,
                'attempt_id' => 84,
                'attempt_number' => 2,
                'failure_code' => 'provider_misconfigured',
            ],
        ]);
        $contextFactory = app(ScenarioContextFactory::class);
        $rendered = $contextFactory->renderContext(
            $contextFactory->evaluationContext($event),
            new ScenarioRecipient('internal', null, null, 'ru'),
        );

        self::assertSame(ScenarioRulePurpose::Transactional, $rule->purpose);
        self::assertSame(NotificationTemplateStatus::Published, $template->status);
        self::assertContains('companion.failure_label', $template->variables);
        self::assertContains('companion.ai_monitoring_url', $template->variables);
        self::assertSame('Ошибка настроек AI-провайдера', $rendered['companion']['failure_label']);
        self::assertSame(2, $rendered['companion']['attempt_number']);
        self::assertSame(route('filament.admin.pages.ai-monitoring-overview'), $rendered['companion']['ai_monitoring_url']);
        self::assertStringNotContainsString('provider_misconfigured', $template->body);
    }

    public function test_untouched_legacy_ai_failure_defaults_are_upgraded_without_rewriting_published_versions(): void
    {
        $organization = Organization::factory()->create();
        $template = NotificationTemplate::factory()->forOrganization($organization)->create([
            'template_key' => 'companion-fallback-failed',
            'name' => 'Сбой передачи обращения специалисту',
            'locale' => 'ru',
            'purpose' => ScenarioRulePurpose::Transactional->value,
        ]);
        $oldVersion = NotificationTemplateVersion::factory()->forTemplate($template)->create([
            'version' => 1,
            'subject' => 'Нужна проверка обращения',
            'body' => 'AI-компаньон не смог продолжить разговор с клиентом {{ client.full_name }}. Проверьте обращение.',
            'variables' => ['client.full_name'],
        ]);
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($oldVersion)->create([
            'rule_key' => 'companion-fallback-failed-database',
            'trigger_event' => ScenarioEventType::CompanionFallbackFailed->value,
            'purpose' => ScenarioRulePurpose::Transactional->value,
            'channel_priority' => ['database'],
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ]);

        app(EnsureOperationalNotificationDefaults::class)->handle($organization);

        self::assertSame(1, $oldVersion->fresh()->version);
        self::assertNotSame($oldVersion->getKey(), $rule->fresh()->template_version_id);
        self::assertSame(2, $template->latestVersion()->firstOrFail()->version);
        self::assertSame('Сбой AI-компаньона', $template->fresh()->name);
        self::assertContains('companion.failure_label', $template->latestVersion()->firstOrFail()->variables);
    }

    public function test_catalog_toggles_only_the_selected_channel_rule_through_existing_scenario_authority(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        app(EnsureOperationalNotificationDefaults::class)->handle($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $crmRule = ScenarioRule::query()->where('organization_id', $organization->getKey())->where('rule_key', 'companion-handoff-database')->sole();
        $telegramRule = ScenarioRule::query()->where('organization_id', $organization->getKey())->where('rule_key', 'companion-handoff-telegram')->sole();

        Livewire::actingAs($admin)
            ->test(NotificationCatalog::class)
            ->call('toggleRule', $telegramRule->getKey())
            ->assertSee('Включено')
            ->assertSee('Включить');

        self::assertTrue($crmRule->fresh()->is_enabled);
        self::assertFalse($telegramRule->fresh()->is_enabled);
    }

    public function test_notification_history_shows_companion_context_and_dialog_action(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create(['name' => 'Chuklov Staging Admin']);
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => 'Murlock Warlock']);
        $template = NotificationTemplate::factory()->forOrganization($organization)->create();
        $version = NotificationTemplateVersion::factory()->forTemplate($template)->create();
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'trigger_event' => ScenarioEventType::CompanionRequestedSpecialist->value,
            'recipient_strategy' => ['type' => 'roles', 'values' => ['administrator']],
        ]);
        $event = ScenarioEvent::factory()->forOrganization($organization)->create([
            'event_name' => ScenarioEventType::CompanionRequestedSpecialist->value,
            'payload' => [
                'client_id' => $client->getKey(),
                'reason' => 'human_requested',
            ],
        ]);
        $action = ScenarioAction::factory()
            ->forOrganization($organization)
            ->forEvent($event)
            ->forRule($rule)
            ->forTemplate($version)
            ->create([
                'recipient_type' => 'internal',
                'recipient_user_id' => $admin->getKey(),
                'trigger_event' => ScenarioEventType::CompanionRequestedSpecialist->value,
                'status' => ScenarioActionStatus::Delivered->value,
                'delivered_at' => now(),
                'render_context' => [
                    'client' => ['full_name' => $client->full_name],
                    'companion' => [
                        'crm_url' => ClientResource::getUrl('companion', ['record' => $client]),
                        'reason' => 'human_requested',
                    ],
                ],
            ]);
        ScenarioDelivery::factory()->forAction($action)->create([
            'priority' => 0,
            'status' => ScenarioDeliveryStatus::Delivered->value,
            'delivered_at' => now(),
        ]);

        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewScenarioAction::class, ['record' => $action->getKey()])
            ->assertSuccessful()
            ->assertSee('Murlock Warlock')
            ->assertSee('Клиент запросил специалиста')
            ->assertSee('Клиент явно попросил специалиста')
            ->assertSee('Telegram')
            ->assertSee('Chuklov Staging Admin')
            ->assertSee('Отправлено')
            ->assertActionExists('openDialog')
            ->assertSee('Открыть диалог');
    }
}
