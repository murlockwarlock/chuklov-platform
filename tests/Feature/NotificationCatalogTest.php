<?php

namespace Tests\Feature;

use App\Filament\Pages\NotificationCatalog;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\EnsureOperationalNotificationDefaults;
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
            ->assertSee('Активные сотрудники CRM с включёнными уведомлениями')
            ->assertSee('CRM')
            ->assertSee('Telegram')
            ->assertSee('Запрос специалиста из AI-компаньона')
            ->assertSee('Включено')
            ->assertSee('Переход по реферальной ссылке')
            ->assertSee('Выключено')
            ->assertSee('Отправок пока нет');
    }
}
