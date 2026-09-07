<?php

namespace Tests\Feature\Scenarios;

use App\Filament\Resources\ContentSections\Pages\ViewContentSection;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Resources\NotificationTemplates\Pages\CreateNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\EditNotificationTemplate;
use App\Filament\Resources\NotificationTemplates\Pages\ViewNotificationTemplate;
use App\Filament\Resources\ScenarioRules\Pages\EditScenarioRule;
use App\Filament\Resources\ScenarioRules\Pages\ViewScenarioRule;
use App\Models\User;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\NotificationTemplateMedia;
use App\Modules\Scenarios\Domain\Contracts\NotificationTemplateRenderer;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
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

    public function test_template_composer_persists_media_only_and_renders_it_for_telegram(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(CreateNotificationTemplate::class)
            ->fillForm([
                'name' => 'Фото для клиента',
                'locale' => 'ru',
                'purpose' => ScenarioRulePurpose::Service->value,
                'is_active' => true,
                'delivery_mode' => NotificationMessageMode::Image->value,
                'media_url' => 'https://cdn.example.test/photo.jpg',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $template = NotificationTemplate::query()->where('name', 'Фото для клиента')->firstOrFail();
        $version = $template->versions()->latest('version')->firstOrFail();

        self::assertSame(NotificationMessageMode::Image, $version->delivery_mode);
        self::assertSame('https://cdn.example.test/photo.jpg', $version->media['items'][0]['source']);

        $rendered = app(NotificationTemplateRenderer::class)->render($version, [], 'ru');
        self::assertSame(NotificationMessageMode::Image, $rendered->mode);
        self::assertSame($version->media, $rendered->media);

        $media = app(NotificationTemplateMedia::class)->messages($organization->getKey(), $version->media);
        self::assertCount(1, $media);
        self::assertSame('photo', $media[0]->type);
        self::assertSame('https://cdn.example.test/photo.jpg', $media[0]->url);
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
            ->assertHasNoFormErrors()
            ->assertRedirect(NotificationTemplateResource::getUrl('view', ['record' => $template->getKey()]));

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

    public function test_scenario_rule_can_create_message_in_modal_with_shared_composer_and_keep_rule_state(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $template = NotificationTemplate::forceCreate([
            'organization_id' => $organization->getKey(),
            'template_key' => 'tpl_existing',
            'locale' => 'ru',
            'name' => 'Существующее сообщение',
            'purpose' => ScenarioRulePurpose::Service,
            'is_active' => true,
        ]);
        $version = NotificationTemplateVersion::forceCreate([
            'organization_id' => $organization->getKey(),
            'template_id' => $template->getKey(),
            'version' => 1,
            'body' => 'Старый текст',
            'variables' => [],
            'status' => NotificationTemplateStatus::Published,
        ]);
        $rule = ScenarioRule::forceCreate([
            'organization_id' => $organization->getKey(),
            'rule_key' => 'rule_modal_message',
            'name' => 'Правило с сообщением',
            'trigger_event' => 'booking.completed',
            'delay_value' => 0,
            'delay_unit' => 'minutes',
            'max_occurrences' => 1,
            'purpose' => ScenarioRulePurpose::Service,
            'template_version_id' => $version->getKey(),
            'conditions' => [],
            'recipient_strategy' => ['type' => 'client'],
            'channel_priority' => ['telegram'],
            'is_enabled' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(EditScenarioRule::class, ['record' => $rule->getKey()])
            ->fillForm([
                'name' => 'Несохранённое название правила',
                'template_version_id' => $version->getKey(),
            ])
            ->assertFormComponentActionExists('template_actions', 'createMessage')
            ->assertFormComponentActionHasLabel('template_actions', 'createMessage', 'Создать текст сообщения');

        $component
            ->callFormComponentAction('template_actions', 'createMessage', [
                'name' => 'Новое сообщение из правила',
                'locale' => 'ru',
                'purpose' => ScenarioRulePurpose::Service->value,
                'is_active' => true,
                'delivery_mode' => NotificationMessageMode::Text->value,
                'caption_position' => 'below',
                'body' => 'Здравствуйте, {{ client.full_name }}!',
            ])
            ->assertHasNoFormComponentActionErrors()
            ->assertNotified('Текст сообщения создан')
            ->assertSet('data.name', 'Несохранённое название правила');

        $newVersion = NotificationTemplateVersion::query()
            ->where('organization_id', $organization->getKey())
            ->whereHas('template', fn (Builder $query): Builder => $query->where('name', 'Новое сообщение из правила'))
            ->sole();

        self::assertStringContainsString('Здравствуйте', $newVersion->body);
        $component->assertSet('data.template_version_id', $newVersion->getKey());

        $component
            ->callFormComponentAction('template_actions', 'editMessage', [
                'template_version_id' => $newVersion->getKey(),
                'name' => 'Изменённое сообщение из правила',
                'locale' => 'ru',
                'purpose' => ScenarioRulePurpose::Service->value,
                'is_active' => true,
                'delivery_mode' => NotificationMessageMode::Text->value,
                'caption_position' => 'below',
                'body' => 'Обновлённый текст, {{ client.full_name }}!',
            ])
            ->assertHasNoFormComponentActionErrors()
            ->assertNotified('Новая версия текста сохранена')
            ->assertSet('data.name', 'Несохранённое название правила');

        $createdTemplate = $newVersion->template;
        self::assertNotNull($createdTemplate);
        self::assertSame(2, $createdTemplate->versions()->count());
        self::assertStringContainsString('Обновлённый текст', $createdTemplate->latestVersion()->firstOrFail()->body);
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
