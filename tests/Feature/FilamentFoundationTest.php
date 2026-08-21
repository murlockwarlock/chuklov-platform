<?php

namespace Tests\Feature;

use App\Filament\Pages\FinanceConfiguration;
use App\Filament\Pages\KnowledgeRetrievalInspector;
use App\Filament\Pages\SchedulingConfiguration;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\ContentSections\ContentSectionResource;
use App\Filament\Resources\FinancialObligations\FinancialObligationResource;
use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
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
use Filament\Facades\Filament;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_panel_boots_and_requires_authentication(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk();
    }

    public function test_admin_navigation_is_grouped_by_business_task(): void
    {
        $panel = Filament::getPanel('admin');
        self::assertNotNull($panel);
        Filament::setCurrentPanel($panel);

        self::assertSame([
            'Клиенты',
            'Записи',
            'Команда и услуги',
            'Коммуникации',
            'Контент и знания',
            'Финансы',
        ], $panel->getNavigationGroups());
        self::assertNotContains(FilamentInfoWidget::class, $panel->getWidgets());

        $expectedGroups = [
            ClientResource::class => 'Клиенты',
            SurveyAttemptResource::class => 'Клиенты',
            BookingResource::class => 'Записи',
            SchedulingConfiguration::class => 'Записи',
            UnavailablePeriodResource::class => 'Записи',
            ScheduleExceptionResource::class => 'Записи',
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
    }
}
