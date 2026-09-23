<?php

namespace Tests\Feature;

use App\Filament\Livewire\DatabaseNotifications;
use App\Filament\Pages\FinanceConfiguration;
use App\Filament\Pages\KnowledgeRetrievalInspector;
use App\Filament\Pages\SchedulingConfiguration;
use App\Filament\Pages\WorkSchedule;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\ContentSections\ContentSectionResource;
use App\Filament\Resources\FinancialObligations\FinancialObligationResource;
use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use App\Filament\Resources\LocationDays\LocationDayResource;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Resources\ScenarioActions\ScenarioActionResource;
use App\Filament\Resources\ScenarioRules\ScenarioRuleResource;
use App\Filament\Resources\ScheduleExceptions\ScheduleExceptionResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Specialists\SpecialistResource;
use App\Filament\Resources\SpecialistServiceAssignments\SpecialistServiceAssignmentResource;
use App\Filament\Resources\SurveyAttempts\SurveyAttemptResource;
use App\Filament\Resources\SurveyDefinitions\SurveyDefinitionResource;
use App\Filament\Resources\UnavailablePeriods\UnavailablePeriodResource;
use App\Filament\Resources\WorkingLocations\WorkingLocationResource;
use App\Filament\Support\CrmLabel;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\B2B\Domain\Enums\B2bLeadStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Finance\Domain\Enums\FinancialStatus;
use App\Modules\Referrals\Domain\Enums\ReferralPartnerStatus;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Tracker\Domain\Enums\TrackerEntitlementSource;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ru');
    }

    protected function tearDown(): void
    {
        app()->setLocale('ru');

        parent::tearDown();
    }

    public function test_admin_panel_boots_and_requires_authentication(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk();
    }

    public function test_admin_navigation_is_grouped_by_business_task(): void
    {
        app()->setLocale('ru');
        $panel = Filament::getPanel('admin');
        self::assertNotNull($panel);
        Filament::setCurrentPanel($panel);

        self::assertSame([
            'Записи',
            'Клиенты',
            'Коммуникации',
            'Настройки',
            'Команда и услуги',
            'Партнёры',
            'Контент и знания',
            'Искусственный интеллект',
            'Финансы',
        ], array_keys($panel->getNavigationGroups()));

        self::assertSame([
            'Записи',
            'Клиенты',
            'Коммуникации',
            'Настройки',
            'Команда и услуги',
            'Партнёры',
            'Контент и знания',
            'Искусственный интеллект',
            'Финансы',
        ], array_map(
            static fn (NavigationGroup|string $group): string => $group instanceof NavigationGroup ? (string) $group->getLabel() : $group,
            array_values($panel->getNavigationGroups()),
        ));
        self::assertNotContains(FilamentInfoWidget::class, $panel->getWidgets());

        $expectedGroups = [
            ClientResource::class => 'Клиенты',
            SurveyAttemptResource::class => 'Клиенты',
            BookingResource::class => 'Записи',
            SchedulingConfiguration::class => 'Записи',
            WorkSchedule::class => 'Записи',
            UnavailablePeriodResource::class => 'Записи',
            ScheduleExceptionResource::class => 'Записи',
            WorkingLocationResource::class => 'Настройки',
            LocationDayResource::class => 'Записи',
            SpecialistResource::class => 'Команда и услуги',
            ServiceResource::class => 'Команда и услуги',
            SpecialistServiceAssignmentResource::class => 'Команда и услуги',
            NotificationTemplateResource::class => 'Коммуникации',
            ScenarioRuleResource::class => 'Коммуникации',
            ScenarioActionResource::class => 'Коммуникации',
            ContentSectionResource::class => 'Контент и знания',
            SurveyDefinitionResource::class => 'Контент и знания',
            KnowledgeSourceResource::class => 'Контент и знания',
            KnowledgeRetrievalInspector::class => 'Контент и знания',
            FinanceConfiguration::class => 'Финансы',
            FinancialObligationResource::class => 'Финансы',
        ];

        foreach ($expectedGroups as $navigationClass => $group) {
            self::assertSame($group, $navigationClass::getNavigationGroup());
        }

        self::assertSame('Контент и знания', KnowledgeSourceResource::getNavigationGroup());
        self::assertSame('База знаний', KnowledgeSourceResource::getNavigationLabel());
        self::assertSame('Контент и знания', KnowledgeRetrievalInspector::getNavigationGroup());
        self::assertSame('Поиск по знаниям', KnowledgeRetrievalInspector::getNavigationLabel());
        self::assertSame('Оплаты', FinancialObligationResource::getNavigationLabel());
        self::assertSame('Настройки валют', FinanceConfiguration::getNavigationLabel());
        self::assertSame(Heroicon::OutlinedCalendarDays, BookingResource::getNavigationIcon());
        self::assertSame(Heroicon::OutlinedClock, WorkSchedule::getNavigationIcon());
        self::assertSame(Heroicon::OutlinedUsers, ClientResource::getNavigationIcon());
    }

    public function test_admin_navigation_labels_follow_the_selected_crm_locale(): void
    {
        app()->setLocale('en');
        $panel = Filament::getPanel('admin');

        self::assertNotNull($panel);
        Filament::setCurrentPanel($panel);

        self::assertSame('Clients', ClientResource::getNavigationLabel());
        self::assertSame('Booking journal', BookingResource::getNavigationLabel());
        self::assertSame('Payments', FinancialObligationResource::getNavigationLabel());
        self::assertSame('Service catalog', ServiceResource::getNavigationLabel());

        $expectedGroups = [
            'Записи' => 'Bookings',
            'Клиенты' => 'Clients',
            'Коммуникации' => 'Communications',
            'Настройки' => 'Settings',
            'Команда и услуги' => 'Team and services',
            'Партнёры' => 'Partners',
            'Контент и знания' => 'Content and knowledge',
            'Искусственный интеллект' => 'Artificial intelligence',
            'Финансы' => 'Finance',
        ];

        foreach ($expectedGroups as $key => $label) {
            self::assertSame($label, $panel->getNavigationGroups()[$key]->getLabel());
        }
    }

    public function test_shared_crm_status_labels_follow_the_selected_locale(): void
    {
        $labels = [
            [BookingStatus::Confirmed, 'Подтверждена', 'Confirmed'],
            [FinancialStatus::Settled, 'Оплачено', 'Paid'],
            [CommerceFulfillmentStatus::Failed, 'Ошибка выдачи', 'Fulfillment failed'],
            [ReferralPartnerStatus::Active, 'Активен', 'Active'],
            [ReferralPayoutRequestStatus::Paid, 'Отмечена как выплаченная', 'Marked as paid'],
            [BroadcastCampaignState::Dispatching, 'Отправляется', 'Sending'],
            [AiRunStatus::TimedOut, 'Превышено время ожидания', 'Timed out'],
            [TrackerEntitlementSource::PaidPurchase, 'Оплаченная покупка', 'Paid purchase'],
            [B2bLeadStatus::ZoomScheduled, 'Разговор запланирован', 'Conversation planned'],
            [VideoMeetingMode::Manual, 'Используется ручная ссылка', 'Using a manual link'],
            [VideoMeetingSyncStatus::ReconciliationRequired, 'Требуется сверка', 'Reconciliation required'],
        ];

        app()->setLocale('ru');
        foreach ($labels as [$status, $russian, $english]) {
            self::assertSame($russian, CrmLabel::enum($status));
        }

        app()->setLocale('en');
        foreach ($labels as [$status, $russian, $english]) {
            self::assertSame($english, CrmLabel::enum($status));
        }
    }

    public function test_database_notifications_keep_the_panel_bell_trigger(): void
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);

        $trigger = app(DatabaseNotifications::class)
            ->getTrigger()
            ->with(['unreadNotificationsCount' => 0])
            ->render();

        self::assertStringContainsString('fi-topbar-database-notifications-btn', $trigger);
    }
}
