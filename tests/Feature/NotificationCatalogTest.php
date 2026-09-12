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
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioDeliveryStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioDelivery;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
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
            ->assertSee('Сотрудники с правом обработки обращений')
            ->assertSee('CRM')
            ->assertSee('Telegram')
            ->assertSee('Запрос специалиста из AI-компаньона')
            ->assertSee('Включено')
            ->assertSee('Переход по реферальной ссылке')
            ->assertSee('Выключено')
            ->assertSee('Отправок пока нет');
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
            ->forClient($client)
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
