<?php

namespace Tests\Feature\Analytics;

use App\Filament\Pages\AnalyticsTaxonomyGroups;
use App\Models\User;
use App\Modules\Analytics\Application\AnalyticsTaxonomyGroupCatalog;
use App\Modules\Analytics\Domain\Enums\AnalyticsTaxonomy;
use App\Modules\Feedback\Domain\Models\FeedbackTheme;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Domain\Models\MedicalCauseDefinition;
use App\Modules\Tracker\Domain\Models\TrackerGoalTag;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class AnalyticsTaxonomyGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_rename_and_deactivate_groups_without_losing_existing_assignments(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        $problem = MedicalProblemTag::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Поясница',
            'group_name' => 'Старая группа',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $feedback = new FeedbackTheme;
        $feedback->forceFill([
            'organization_id' => $organization->getKey(),
            'name' => 'Сервис',
            'group_name' => 'Обратная связь',
            'is_active' => true,
            'sort_order' => 0,
        ])->save();

        $component = Livewire::actingAs($admin)->test(AnalyticsTaxonomyGroups::class);
        $state = $component->get('data');
        $problemKey = array_key_first($state['problems']);
        $feedbackKey = array_key_first($state['feedback']);
        self::assertIsString($problemKey);
        self::assertIsString($feedbackKey);
        self::assertSame('Старая группа', $state['problems'][$problemKey]['name']);
        self::assertSame('Обратная связь', $state['feedback'][$feedbackKey]['name']);

        $state['problems'][$problemKey]['name'] = 'Опорно-двигательная система';
        $state['causes'] = [['id' => null, 'name' => 'Функциональные причины', 'is_active' => true, 'sort_order' => 0]];
        $state['goals'] = [['id' => null, 'name' => 'Восстановление', 'is_active' => true, 'sort_order' => 0]];
        $state['feedback'][$feedbackKey]['is_active'] = false;
        $component->fillForm($state)
            ->call('save')
            ->assertNotified('Группы аналитики сохранены');

        self::assertSame('Опорно-двигательная система', $problem->refresh()->group_name);
        self::assertDatabaseHas('analytics_taxonomy_groups', [
            'organization_id' => $organization->getKey(),
            'taxonomy' => AnalyticsTaxonomy::Causes->value,
            'name' => 'Функциональные причины',
            'is_active' => true,
        ]);
        self::assertDatabaseHas('analytics_taxonomy_groups', [
            'organization_id' => $organization->getKey(),
            'taxonomy' => AnalyticsTaxonomy::Feedback->value,
            'name' => 'Обратная связь',
            'is_active' => false,
        ]);

        self::assertSame(
            ['Опорно-двигательная система' => 'Опорно-двигательная система'],
            app(AnalyticsTaxonomyGroupCatalog::class)->options($organization->getKey(), AnalyticsTaxonomy::Problems),
        );
        self::assertSame([], app(AnalyticsTaxonomyGroupCatalog::class)->options($organization->getKey(), AnalyticsTaxonomy::Feedback));
    }

    public function test_groups_are_tenant_scoped_and_cannot_be_renamed_into_a_duplicate(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        [$otherOrganization] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);

        $first = MedicalCauseDefinition::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Первая причина',
            'group_name' => 'Первая группа',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        MedicalCauseDefinition::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Вторая причина',
            'group_name' => 'Вторая группа',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $foreignGoal = new TrackerGoalTag;
        $foreignGoal->forceFill([
            'organization_id' => $otherOrganization->getKey(),
            'name' => 'Чужая цель',
            'group_name' => 'Чужая группа',
            'is_active' => true,
            'sort_order' => 0,
        ])->save();

        $component = Livewire::actingAs($admin)->test(AnalyticsTaxonomyGroups::class);
        $state = $component->get('data');
        $causeKeys = array_keys($state['causes']);
        self::assertCount(2, $causeKeys);
        self::assertSame([], $state['goals']);
        $state['causes'][$causeKeys[0]]['name'] = $state['causes'][$causeKeys[1]]['name'];

        $component->fillForm($state)->call('save')->assertNotified('Названия групп внутри раздела должны быть уникальными.');
        self::assertSame('Первая группа', $first->refresh()->group_name);
        self::assertDatabaseMissing('analytics_taxonomy_groups', ['organization_id' => $organization->getKey(), 'taxonomy' => AnalyticsTaxonomy::Goals->value, 'name' => 'Чужая группа']);
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
