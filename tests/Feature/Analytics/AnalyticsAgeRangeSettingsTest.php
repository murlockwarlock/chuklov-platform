<?php

namespace Tests\Feature\Analytics;

use App\Filament\Pages\AnalyticsAgeRangeSettings;
use App\Models\User;
use App\Modules\Analytics\Application\ClientAgeRangeCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class AnalyticsAgeRangeSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_overlapping_ranges_show_an_actionable_error_without_partial_write(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::AnalyticsAgeRanges,
            json_encode([
                ['key' => 'children', 'label' => 'Дети', 'min' => 0, 'max' => 17, 'active' => true, 'sort_order' => 10],
                ['key' => 'adults', 'label' => 'Взрослые', 'min' => 18, 'max' => null, 'active' => true, 'sort_order' => 20],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );

        $component = Livewire::actingAs($admin)->test(AnalyticsAgeRangeSettings::class);
        $state = $component->instance()->data;
        self::assertIsArray($state);
        self::assertIsArray($state['ranges'] ?? null);
        $state['ranges']['qa-overlap'] = [
            'key' => 'qa_overlap',
            'label' => 'QA overlap',
            'min' => 10,
            'max' => 20,
            'active' => true,
            'sort_order' => 30,
        ];

        $component->fillForm($state)
            ->call('save')
            ->assertNotified('Не удалось сохранить возрастные диапазоны');

        $rows = app(ClientAgeRangeCatalog::class)->rows($organization->getKey(), false);
        self::assertSame(['children', 'adults'], array_column($rows, 'key'));
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
