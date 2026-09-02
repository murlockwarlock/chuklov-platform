<?php

namespace Tests\Feature;

use App\Filament\Pages\SchedulingConfiguration;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\BroadcastCampaigns\Pages\CreateBroadcastCampaign;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Filament\Resources\ScenarioActions\Pages\ListScenarioActions;
use App\Filament\Resources\ScenarioRules\Pages\CreateScenarioRule;
use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scenarios\Application\AppointmentReminderScheduler;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\RecordingNotificationChannel;
use Tests\TestCase;

final class CrmUxRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 9, 2, 10, 0, 0, 'UTC'));
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([new RecordingNotificationChannel]));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_broadcast_and_auto_message_forms_use_operator_language_and_direct_message_defaults(): void
    {
        [$organization, $admin] = $this->fixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $broadcast = Livewire::actingAs($admin)->test(CreateBroadcastCampaign::class);
        $broadcast
            ->assertSuccessful()
            ->assertFormFieldExists('audience_type')
            ->assertFormFieldExists('selected_client_ids')
            ->assertFormFieldExists('message_mode')
            ->assertFormFieldExists('message_body')
            ->assertSee('Выбрать клиентов')
            ->assertSee('Написать сообщение')
            ->assertSee('Получателей');

        self::assertSame('Клиенты', $broadcast->instance()->getSchemaComponent('form.selected_client_ids')->getLabel());
        self::assertTrue($broadcast->instance()->getSchemaComponent('form.selected_client_ids')->isMultiple());
        self::assertTrue($broadcast->instance()->getSchemaComponent('form.selected_client_ids')->isSearchable());

        Livewire::actingAs($admin)
            ->test(CreateScenarioRule::class)
            ->assertSuccessful()
            ->assertSee('1. Когда?')
            ->assertSee('Перед визитом')
            ->assertSee('2. Кому?')
            ->assertSee('3. Что отправить?')
            ->assertSee('4. Включить?')
            ->assertSee('Дополнительные настройки');
    }

    public function test_operational_tables_keep_primary_actions_compact_and_human(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture(includePeople: true);
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Confirmed->value,
                'visit_format' => VisitFormat::Office->value,
                'starts_at' => CarbonImmutable::now()->addDays(2),
                'ends_at' => CarbonImmutable::now()->addDays(2)->addHour(),
                'blocking_ends_at' => CarbonImmutable::now()->addDays(2)->addHour(),
            ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $clients = Livewire::actingAs($admin)->test(ListClients::class)->assertSuccessful();
        $clientView = $clients->instance()->getTable()->getAction('view');
        $clientEdit = $clients->instance()->getTable()->getAction('edit');
        self::assertNotNull($clientView);
        self::assertNotNull($clientEdit);
        self::assertSame(Heroicon::OutlinedEye, $clientView->getIcon());
        self::assertSame(Heroicon::OutlinedPencil, $clientEdit->getIcon());
        $clientIdColumn = $clients->instance()->getTable()->getColumn('id');
        self::assertNotNull($clientIdColumn);
        self::assertTrue($clientIdColumn->isToggleable());
        self::assertTrue($clientIdColumn->isToggledHiddenByDefault());

        $bookings = Livewire::actingAs($admin)
            ->test(ListBookings::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$booking]);
        $bookingAttentionColumn = $bookings->instance()->getTable()->getColumn('needs_attention');
        self::assertNotNull($bookingAttentionColumn);
        self::assertTrue($bookingAttentionColumn->isToggleable());
        self::assertTrue($bookingAttentionColumn->isToggledHiddenByDefault());
        $bookingView = $bookings->instance()->getTable()->getAction('view');
        self::assertNotNull($bookingView);
        self::assertSame(Heroicon::OutlinedEye, $bookingView->getIcon());
    }

    public function test_scheduling_page_exposes_first_class_reminders_and_default_address(): void
    {
        [$organization, $admin] = $this->fixture(includePeople: true);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(SchedulingConfiguration::class)
            ->assertSuccessful()
            ->assertSee('Адрес по умолчанию')
            ->assertSee('Напоминания о записи')
            ->assertSee('Клиенту')
            ->assertSee('Себе / специалисту')
            ->assertSee('Добавить напоминание');
    }

    public function test_history_table_uses_human_columns_and_hides_technical_rule_keys(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture(includePeople: true);
        $booking = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Confirmed->value,
                'visit_format' => VisitFormat::Office->value,
                'starts_at' => CarbonImmutable::now()->addDays(2),
                'ends_at' => CarbonImmutable::now()->addDays(2)->addHour(),
                'blocking_ends_at' => CarbonImmutable::now()->addDays(2)->addHour(),
            ]);
        $event = app(RecordScenarioEvent::class)->bookingConfirmed($booking, 'history-test', CarbonImmutable::now());
        app(AppointmentReminderScheduler::class)->schedule($booking, $event);
        $action = ScenarioAction::query()->where('booking_id', $booking->getKey())->where('recipient_type', 'client')->firstOrFail();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ListScenarioActions::class)
            ->assertTableColumnExists('activity_at')
            ->assertTableColumnExists('message_summary')
            ->assertTableColumnExists('recipient_summary')
            ->assertTableColumnExists('status_summary')
            ->assertTableColumnDoesNotExist('rule_key')
            ->assertSee('Напоминание о визите')
            ->assertSee('Запланировано')
            ->assertDontSee('appointment-reminder');
        $history = Livewire::actingAs($admin)->test(ListScenarioActions::class)->assertSuccessful();
        $historyView = $history->instance()->getTable()->getAction('view');
        self::assertNotNull($historyView);
        self::assertSame(Heroicon::OutlinedEye, $historyView->getIcon());
    }

    public function test_templates_are_newest_first_with_preview_and_compact_actions(): void
    {
        [$organization, $admin] = $this->fixture();
        $older = NotificationTemplate::factory()->forOrganization($organization)->create([
            'name' => 'Старое сообщение',
            'locale' => 'ru',
        ]);
        NotificationTemplateVersion::factory()->forTemplate($older)->create(['body' => 'Старый текст']);
        $newer = NotificationTemplate::factory()->forOrganization($organization)->create([
            'name' => 'Новое сообщение',
            'locale' => 'ru',
        ]);
        NotificationTemplateVersion::factory()->forTemplate($newer)->create(['body' => 'Новый текст']);
        $timestamp = CarbonImmutable::create(2026, 9, 1, 10, 0, 0, 'UTC');
        DB::table('notification_templates')->where('id', $older->getKey())->update(['created_at' => $timestamp, 'updated_at' => $timestamp]);
        DB::table('notification_templates')->where('id', $newer->getKey())->update(['created_at' => $timestamp, 'updated_at' => $timestamp]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)->test(ListNotificationTemplates::class);

        self::assertSame([$newer->getKey(), $older->getKey()], $component->instance()->getTableRecords()->pluck('id')->all());
        $component
            ->assertTableColumnStateSet('latestVersion.body', 'Новый текст', $newer)
            ->assertSee('Предпросмотр')
            ->assertSee('Для чего');
        $view = $component->instance()->getTable()->getAction('view');
        $edit = $component->instance()->getTable()->getAction('edit');
        self::assertNotNull($view);
        self::assertNotNull($edit);
        self::assertSame(Heroicon::OutlinedEye, $view->getIcon());
        self::assertSame(Heroicon::OutlinedPencil, $edit->getIcon());
    }

    /** @return array{Organization, User, Client|null, Specialist|null, Service|null} */
    private function fixture(bool $includePeople = false): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        if (! $includePeople) {
            return [$organization, $admin, null, null, null];
        }

        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);

        $client = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Aikhana',
            'language' => 'ru',
            'timezone' => 'UTC',
        ]);
        ClientChannelIdentity::factory()->forClient($client)->create([
            'channel' => 'telegram',
            'external_id' => 'client-'.$client->getKey(),
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verification_method' => 'test',
            'verified_at' => now(),
        ]);
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create(['name' => 'Евгений Чуклов']);
        OrganizationChannelIdentity::factory()->forUser($staff)->verified()->create(['external_id' => 'staff-'.$staff->getKey()]);
        $specialist = Specialist::factory()->forOrganization($organization)->create([
            'display_name' => 'Евгений Чуклов',
            'staff_user_id' => $staff->getKey(),
        ]);
        $service = Service::factory()->forOrganization($organization)->create(['name' => 'Массаж тела']);

        return [$organization, $admin, $client, $specialist, $service];
    }
}
