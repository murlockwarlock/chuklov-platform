<?php

namespace Tests\Feature\Scenarios;

use App\Filament\Resources\ContentSections\Pages\ViewContentSection;
use App\Filament\Resources\NotificationTemplates\Pages\CreateNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ViewNotificationTemplate;
use App\Filament\Resources\ScenarioRules\Pages\ViewScenarioRule;
use App\Models\User;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class NotificationTemplateUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_variables_are_automatically_derived_from_body_and_subject_on_create(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(CreateNotificationTemplate::class)
            ->fillForm([
                'name' => 'Напоминание о визите',
                'locale' => 'ru',
                'purpose' => ScenarioRulePurpose::Service->value,
                'is_active' => true,
                'subject' => 'Напоминание для {{ client.full_name }}',
                'body' => 'Здравствуйте, {{ client.full_name }}! Ваш визит: {{ booking.starts_at }} на услугу {{ booking.service_name }}.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $template = NotificationTemplate::query()->where('name', 'Напоминание о визите')->firstOrFail();
        $latestVersion = $template->versions()->latest('version')->firstOrFail();

        self::assertEqualsCanonicalizing([
            'booking.service_name',
            'booking.starts_at',
            'client.full_name',
        ], $latestVersion->variables, 'Strict allowlist variables derived automatically');
    }

    public function test_template_with_unsupported_variable_is_rejected_on_create(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(CreateNotificationTemplate::class)
            ->fillForm([
                'name' => 'Ошибочный шаблон',
                'locale' => 'ru',
                'purpose' => ScenarioRulePurpose::Service->value,
                'is_active' => true,
                'body' => 'Текст с ошибкой {{ invalid.variable }}',
            ])
            ->call('create')
            ->assertHasErrors(['body']);
    }

    public function test_template_variables_are_automatically_derived_on_edit(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();

        $template = NotificationTemplate::forceCreate([
            'organization_id' => $organization->id,
            'template_key' => 'reminder_visit',
            'locale' => 'ru',
            'name' => 'Старый шаблон',
            'purpose' => ScenarioRulePurpose::Service,
            'is_active' => true,
        ]);

        $template->versions()->forceCreate([
            'organization_id' => $organization->id,
            'template_id' => $template->id,
            'version' => 1,
            'subject' => null,
            'body' => 'Старый текст {{ client.full_name }}',
            'variables' => ['client.full_name'],
            'status' => NotificationTemplateStatus::Published,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(EditNotificationTemplate::class, ['record' => $template->getKey()])
            ->fillForm([
                'name' => 'Обновленный шаблон',
                'purpose' => ScenarioRulePurpose::Transactional->value,
                'is_active' => true,
                'subject' => 'Новая тема {{ booking.starts_at }}',
                'body' => 'Новый текст для языка {{ client.language }} и услуги {{ booking.service_name }}',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $latestVersion = $template->versions()->latest('version')->firstOrFail();
        self::assertSame(2, $latestVersion->version);
        self::assertEqualsCanonicalizing([
            'booking.service_name',
            'booking.starts_at',
            'client.language',
        ], $latestVersion->variables);
    }

    public function test_detail_pages_expose_header_edit_actions(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();

        $template = NotificationTemplate::forceCreate([
            'organization_id' => $organization->id,
            'template_key' => 'tpl_test',
            'locale' => 'ru',
            'name' => 'Тестовый шаблон',
            'purpose' => ScenarioRulePurpose::Service,
            'is_active' => true,
        ]);
        $template->versions()->forceCreate([
            'organization_id' => $organization->id,
            'template_id' => $template->id,
            'version' => 1,
            'body' => '<p><strong>Текст</strong></p>',
            'variables' => [],
            'status' => NotificationTemplateStatus::Published,
        ]);

        $version = $template->versions()->first();
        $rule = ScenarioRule::forceCreate([
            'organization_id' => $organization->id,
            'rule_key' => 'rule_test',
            'name' => 'Тестовое правило',
            'trigger_event' => 'booking.completed',
            'delay_value' => 10,
            'delay_unit' => 'minutes',
            'max_occurrences' => 1,
            'purpose' => ScenarioRulePurpose::Service,
            'template_version_id' => $version->id,
            'conditions' => [],
            'recipient_strategy' => ['type' => 'client'],
            'channel_priority' => ['telegram'],
            'is_enabled' => true,
        ]);

        $section = ContentSection::forceCreate([
            'organization_id' => $organization->id,
            'locale' => 'ru',
            'section_key' => 'about_us',
            'title' => 'О клинике',
            'body' => 'Текст о клинике',
            'sort_order' => 1,
            'is_visible' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        $templateView = Livewire::test(ViewNotificationTemplate::class, ['record' => $template->getKey()])
            ->assertSuccessful()
            ->assertActionExists('edit');

        self::assertStringContainsString('<strong>Текст</strong>', $templateView->html());
        self::assertStringNotContainsString('&lt;strong&gt;', $templateView->html());

        Livewire::test(ViewScenarioRule::class, ['record' => $rule->getKey()])
            ->assertSuccessful()
            ->assertActionExists('edit');

        Livewire::test(ViewContentSection::class, ['record' => $section->getKey()])
            ->assertSuccessful()
            ->assertActionExists('edit');
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();

        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }
}
