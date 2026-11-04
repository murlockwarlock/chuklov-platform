<?php

namespace Tests\Feature\Analytics;

use App\Filament\Pages\AnalyticsPurchaseGroupSettings;
use App\Models\User;
use App\Modules\Analytics\Application\PurchaseGroupCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class AnalyticsPurchaseGroupSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_active_catalog_types_show_an_actionable_error_without_partial_write(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::AnalyticsPurchaseGroups,
            json_encode([
                ['key' => 'services', 'label' => 'Разовые сеансы', 'catalog_types' => ['service'], 'active' => true, 'sort_order' => 10],
                ['key' => 'programs', 'label' => 'Пакеты и программы', 'catalog_types' => ['program'], 'active' => true, 'sort_order' => 20],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );

        $component = Livewire::actingAs($admin)->test(AnalyticsPurchaseGroupSettings::class);
        $state = $component->instance()->data;
        self::assertIsArray($state);
        self::assertIsArray($state['groups'] ?? null);
        $state['groups']['qa-duplicate'] = [
            'key' => 'qa_duplicate',
            'label' => 'QA duplicate',
            'catalog_types' => ['service'],
            'active' => true,
            'sort_order' => 30,
        ];

        $component->fillForm($state)
            ->call('save')
            ->assertNotified('Не удалось сохранить группы покупок');

        $rows = app(PurchaseGroupCatalog::class)->rows($organization->getKey(), false);
        self::assertSame(['services', 'programs', 'unknown'], array_column($rows, 'key'));
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return [$organization, $admin];
    }
}
