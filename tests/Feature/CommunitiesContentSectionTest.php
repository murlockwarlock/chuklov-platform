<?php

namespace Tests\Feature;

use App\Filament\Resources\ContentSections\Pages\CreateContentSection as CreateContentSectionPage;
use App\Filament\Resources\ContentSections\Pages\EditContentSection;
use App\Filament\Resources\ContentSections\Pages\ViewContentSection;
use App\Models\User;
use App\Modules\Channels\Application\BuildTelegramContentSectionMessage;
use App\Modules\Channels\Application\GetTelegramMenu;
use App\Modules\Channels\Application\SendTelegramContentSection;
use App\Modules\Channels\Application\TelegramMessagePreview;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\NotificationActionButton;
use App\Modules\Content\Application\CreateContentSection;
use App\Modules\Content\Application\ListPublishedContentSections;
use App\Modules\Content\Domain\Enums\ContentDeliveryMode;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Identity\Application\VerifiedChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Support\RichText\RichTextDocument;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Livewire\Features\SupportTesting\Testable;
use Psr\Http\Message\RequestInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

final class CommunitiesContentSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_communities_is_registered_in_the_existing_content_section_form(): void
    {
        [, $admin] = $this->filamentOrganizationAndAdmin();
        Testable::actingAs($admin);
        $page = $this->createContentSectionPage();
        $sectionSelect = $page->getSchemaComponent('form.section_key');

        self::assertInstanceOf(Select::class, $sectionSelect);
        self::assertSame('Сообщества', $sectionSelect->getOptions()['communities'] ?? null);
        self::assertSame(
            ['en' => 'Communities', 'ru' => 'Сообщества'],
            config('portal.content_sections.communities.title'),
        );
        self::assertNull($page->getSchemaComponent('form.media.alt'));
    }

    public function test_communities_can_be_created_without_image_metadata(): void
    {
        [$organization, $admin] = $this->filamentOrganizationAndAdmin();
        Testable::actingAs($admin);

        Testable::create(CreateContentSectionPage::class)
            ->assertFormFieldDoesNotExist('media.alt')
            ->fillForm([
                'section_key' => 'communities',
                'locale' => 'ru',
                'title' => 'Сообщества',
                'body' => '<p>Содержание сообществ.</p>',
                'delivery_mode' => ContentDeliveryMode::Both->value,
                'sort_order' => 0,
                'is_visible' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $section = ContentSection::query()
            ->where('organization_id', $organization->getKey())
            ->where('section_key', 'communities')
            ->where('locale', 'ru')
            ->latest('id')
            ->firstOrFail();

        self::assertNull($section->media);

        Testable::create(ViewContentSection::class, ['record' => $section->getKey()])
            ->assertSuccessful()
            ->assertSee('Не добавлено');
    }

    public function test_filament_rich_editor_link_state_survives_create_edit_portal_and_telegram_delivery(): void
    {
        [$organization, $admin] = $this->filamentOrganizationAndAdmin();
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        $bot = $this->fakeNutgram();

        $links = [
            'https://t.me/test_one',
            'https://t.me/test_two',
            'https://t.me/+testThree',
            'https://example.test/community-four',
        ];
        $labels = [
            'Вибрационная медицина',
            'Global Resonance',
            'Авторский канал',
            'Канал для Избранных массажистов',
        ];

        Testable::actingAs($admin);
        Testable::create(CreateContentSectionPage::class)
            ->fillForm([
                'section_key' => 'communities',
                'locale' => 'ru',
                'title' => 'Сообщества',
                'body' => $this->filamentRichEditorLinkState($labels, $links),
                'delivery_mode' => ContentDeliveryMode::Both->value,
                'sort_order' => 0,
                'is_visible' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $section = ContentSection::query()
            ->where('organization_id', $organization->getKey())
            ->where('section_key', 'communities')
            ->where('locale', 'ru')
            ->latest('id')
            ->firstOrFail();

        foreach ($links as $link) {
            self::assertStringContainsString($link, $section->body);
        }
        self::assertSame(RichTextDocument::canonicalHtml($section->body), $section->body);

        config()->set('tenancy.default_organization_id', $organization->getKey());
        $this->withSession(['portal.locale' => 'ru'])
            ->get(route('portal.section', ['section' => 'communities']))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('content.0.bodyHtml', function (string $bodyHtml) use ($labels, $links): bool {
                    foreach ($labels as $index => $label) {
                        $pattern = '/<a\b[^>]*href="'.preg_quote($links[$index], '/').'"[^>]*>'.preg_quote($label, '/').'<\/a>/u';
                        if (preg_match($pattern, $bodyHtml) !== 1) {
                            return false;
                        }
                    }

                    return substr_count($bodyHtml, '<a ') === count($labels);
                }));

        app(SendTelegramContentSection::class)->handle(
            new VerifiedChannelIdentity('telegram', 'communities-chat', 'Communities client', 'ru'),
            'communities',
            'ru',
        );

        $bot->assertRaw(function (RequestInterface $request) use ($labels, $links): bool {
            $payload = json_decode((string) $request->getBody(), true);
            $text = is_array($payload) ? ($payload['text'] ?? '') : '';

            if (! is_string($text)) {
                return false;
            }

            foreach ($labels as $index => $label) {
                if (! str_contains($text, '<a href="'.$links[$index].'">'.$label.'</a>')) {
                    return false;
                }
            }

            $button = $payload['reply_markup']['inline_keyboard'][0][0] ?? null;

            return is_array($button)
                && ($button['text'] ?? null) === 'Открыть полностью'
                && ($button['url'] ?? null) === 'https://mini.example.test/portal/telegram/launch/communities';
        }, index: 0);

        $updatedLinks = [...$links];
        $updatedLinks[0] = 'https://t.me/test_one_updated';

        $edit = Testable::create(EditContentSection::class, ['record' => $section->getKey()]);
        $reloadedBody = $edit->get('data.body');
        self::assertIsArray($reloadedBody);
        $reloadedBodyJson = json_encode($reloadedBody, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ($links as $link) {
            self::assertStringContainsString($link, $reloadedBodyJson);
        }
        $edit
            ->fillForm([
                'body' => $this->filamentRichEditorLinkState($labels, $updatedLinks),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $section->refresh();
        self::assertStringNotContainsString('href="'.$links[0].'"', $section->body);
        self::assertStringContainsString($updatedLinks[0], $section->body);

        app(SendTelegramContentSection::class)->handle(
            new VerifiedChannelIdentity('telegram', 'communities-chat', 'Communities client', 'ru'),
            'communities',
            'ru',
        );

        $bot->assertRaw(function (RequestInterface $request) use ($labels, $links, $updatedLinks): bool {
            $payload = json_decode((string) $request->getBody(), true);
            $text = is_array($payload) ? ($payload['text'] ?? '') : '';

            if (! is_string($text)) {
                return false;
            }

            foreach ($labels as $index => $label) {
                if (! str_contains($text, '<a href="'.$updatedLinks[$index].'">'.$label.'</a>')) {
                    return false;
                }
            }

            return ! str_contains($text, '<a href="'.$links[0].'">');
        }, index: 1);
    }

    public function test_unpublished_communities_do_not_create_a_dead_telegram_menu_action(): void
    {
        [$organization] = $this->organizationAndAdmin();
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');

        self::assertNull(collect(app(GetTelegramMenu::class)->handle('ru'))->firstWhere('key', 'communities'));

        ContentSection::factory()->forOrganization($organization)->hidden()->create([
            'section_key' => 'communities',
            'locale' => 'ru',
            'delivery_mode' => ContentDeliveryMode::Both->value,
        ]);

        self::assertNull(collect(app(GetTelegramMenu::class)->handle('ru'))->firstWhere('key', 'communities'));
        self::assertCount(0, app(ListPublishedContentSections::class)->handle('communities'));
    }

    public function test_telegram_content_uses_the_existing_content_callback(): void
    {
        [$organization] = $this->organizationAndAdmin();
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'communities',
            'locale' => 'ru',
            'delivery_mode' => ContentDeliveryMode::Telegram,
        ]);

        $entry = collect(app(GetTelegramMenu::class)->handle('ru'))->firstWhere('key', 'communities');

        self::assertIsArray($entry);
        self::assertSame('telegram_content', $entry['launch']);
        self::assertSame('content:communities', $entry['callback_data'] ?? null);
        self::assertSame('Сообщества', $entry['label']);
    }

    public function test_mini_app_content_uses_the_existing_portal_section_launch(): void
    {
        [$organization] = $this->organizationAndAdmin();
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'communities',
            'locale' => 'ru',
            'delivery_mode' => ContentDeliveryMode::MiniApp,
        ]);

        $entry = collect(app(GetTelegramMenu::class)->handle('ru'))->firstWhere('key', 'communities');

        self::assertIsArray($entry);
        self::assertSame('mini_app', $entry['launch']);
        self::assertTrue($entry['web_app']);
        self::assertSame('https://mini.example.test/portal/telegram/launch/communities', $entry['url']);
        self::assertArrayNotHasKey('callback_data', $entry);

        $this->get(route('portal.telegram.launch', ['entry' => 'communities']))
            ->assertRedirect(route('portal.section', ['section' => 'communities'], false));
    }

    public function test_both_content_keeps_the_existing_telegram_projection_and_mini_app_cta(): void
    {
        [$organization] = $this->organizationAndAdmin();
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'communities',
            'locale' => 'ru',
            'title' => 'Сообщества',
            'body' => '<p><a href="https://communities.example.test/one">Ссылка</a></p>',
            'delivery_mode' => ContentDeliveryMode::Both,
        ]);

        $entry = collect(app(GetTelegramMenu::class)->handle('ru'))->firstWhere('key', 'communities');
        $message = app(BuildTelegramContentSectionMessage::class)->handle('community-chat', $section, 'ru');
        $preview = app(TelegramMessagePreview::class)->handle($message);

        self::assertIsArray($entry);
        self::assertSame('telegram_content', $entry['launch']);
        self::assertSame(NotificationMessageMode::Text, $message->mode);
        self::assertInstanceOf(NotificationActionButton::class, $message->actionButton);
        self::assertSame('Открыть полностью', $message->actionButton->text);
        self::assertSame('https://mini.example.test/portal/telegram/launch/communities', $message->actionButton->url);
        self::assertStringContainsString('https://communities.example.test/one', $preview['bodyHtml']);
    }

    public function test_telegram_content_is_sent_through_the_existing_content_delivery_action(): void
    {
        [$organization] = $this->organizationAndAdmin();
        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'communities',
            'locale' => 'ru',
            'body' => '<p>Содержание сообществ.</p>',
            'delivery_mode' => ContentDeliveryMode::Telegram,
        ]);
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        $bot = $this->fakeNutgram();

        $result = app(SendTelegramContentSection::class)->handle(
            new VerifiedChannelIdentity('telegram', 'communities-chat', 'Communities client', 'ru'),
            'communities',
            'ru',
        );

        self::assertSame('delivered', $result->outcome->value);
        self::assertInstanceOf(FakeNutgram::class, $bot);
        $bot->assertCalled('sendMessage');
    }

    public function test_telegram_communities_callback_delivers_content_and_acknowledges_the_button(): void
    {
        [$organization] = $this->organizationAndAdmin();
        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'communities',
            'locale' => 'ru',
            'body' => '<p>Содержание сообществ.</p>',
            'delivery_mode' => ContentDeliveryMode::Telegram,
        ]);
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
        $bot = $this->fakeNutgram();
        $bot->setCommonUser(TelegramUser::make(
            id: 777001,
            is_bot: false,
            first_name: 'Test',
            last_name: 'Client',
            language_code: 'ru',
        ));

        $bot->hearCallbackQueryData('content:communities')->reply();

        $bot->assertReply('sendMessage', index: 0);
        $bot->assertReply('answerCallbackQuery', ['text' => 'Готово.'], 1);
    }

    public function test_communities_localization_and_existing_locale_fallback_are_preserved(): void
    {
        [$organization] = $this->organizationAndAdmin();
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'communities',
            'locale' => 'ru',
            'title' => 'Русские сообщества',
            'body' => 'Русский текст.',
            'delivery_mode' => ContentDeliveryMode::Both,
        ]);
        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'communities',
            'locale' => 'en',
            'title' => 'English communities',
            'body' => 'English text.',
            'delivery_mode' => ContentDeliveryMode::Both,
        ]);

        $ruEntry = collect(app(GetTelegramMenu::class)->handle('ru'))->firstWhere('key', 'communities');
        $enEntry = collect(app(GetTelegramMenu::class)->handle('en'))->firstWhere('key', 'communities');

        self::assertIsArray($ruEntry);
        self::assertIsArray($enEntry);
        self::assertSame('Сообщества', $ruEntry['label']);
        self::assertSame('Communities', $enEntry['label']);

        config()->set('tenancy.default_organization_id', $organization->getKey());
        $this->withSession(['portal.locale' => 'ru'])
            ->get(route('portal.section', ['section' => 'communities']))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Section')
                ->where('locale', 'ru')
                ->where('title', 'Сообщества')
                ->where('content.0.title', 'Русские сообщества'));
        $this->withSession(['portal.locale' => 'en'])
            ->get(route('portal.section', ['section' => 'communities']))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Section')
                ->where('locale', 'en')
                ->where('title', 'Communities')
                ->where('content.0.title', 'English communities'));
    }

    public function test_communities_fall_back_to_the_other_published_locale(): void
    {
        [$organization] = $this->organizationAndAdmin();
        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'communities',
            'locale' => 'en',
            'title' => 'English communities',
            'body' => 'English fallback.',
            'delivery_mode' => ContentDeliveryMode::MiniApp,
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());

        $this->withSession(['portal.locale' => 'ru'])
            ->get(route('portal.section', ['section' => 'communities']))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Section')
                ->where('locale', 'en')
                ->where('title', 'Communities')
                ->where('content.0.title', 'English communities'));
    }

    public function test_communities_remain_organization_scoped_and_invisible_content_is_not_rendered(): void
    {
        [$organization] = $this->organizationAndAdmin();
        [$otherOrganization] = $this->organizationAndAdmin();
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'communities',
            'locale' => 'ru',
            'delivery_mode' => ContentDeliveryMode::Both,
        ]);
        ContentSection::factory()->forOrganization($otherOrganization)->hidden()->create([
            'section_key' => 'communities',
            'locale' => 'ru',
            'delivery_mode' => ContentDeliveryMode::Both,
        ]);

        $this->setOrganization($otherOrganization);
        self::assertNull(collect(app(GetTelegramMenu::class)->handle('ru'))->firstWhere('key', 'communities'));
        $this->withSession(['portal.locale' => 'ru'])
            ->get(route('portal.section', ['section' => 'communities']))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Section')
                ->has('content', 0));

        $this->setOrganization($organization);
        self::assertNotNull(collect(app(GetTelegramMenu::class)->handle('ru'))->firstWhere('key', 'communities'));
    }

    public function test_communities_render_safe_https_links_and_existing_images(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        $section = app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'communities',
            'locale' => 'ru',
            'title' => 'Сообщества',
            'body' => '<p><a href="https://communities.example.test/one">Первая ссылка</a> <a href="javascript:alert(1)">Небезопасная ссылка</a> <a href="data:text/html,unsafe">Данные</a> <a href="vbscript:alert(1)">Сценарий</a></p>',
            'delivery_mode' => ContentDeliveryMode::Both->value,
            'media' => ['image' => 'https://cdn.example.test/communities.jpg', 'alt' => 'Сообщества'],
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());

        self::assertStringNotContainsString('javascript:', strtolower($section->body));
        self::assertStringNotContainsString('data:text', strtolower($section->body));
        self::assertStringNotContainsString('vbscript:', strtolower($section->body));

        $this->withSession(['portal.locale' => 'ru'])
            ->get(route('portal.section', ['section' => 'communities']))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Section')
                ->where('content.0.bodyHtml', fn (string $bodyHtml): bool => str_contains($bodyHtml, 'https://communities.example.test/one')
                    && ! str_contains(strtolower($bodyHtml), 'javascript:')
                    && ! str_contains(strtolower($bodyHtml), 'data:text')
                    && ! str_contains(strtolower($bodyHtml), 'vbscript:'))
                ->where('content.0.media.image', 'https://cdn.example.test/communities.jpg')
                ->where('content.0.media.alt', 'Сообщества'));

        $preview = app(TelegramMessagePreview::class)->handle(
            app(BuildTelegramContentSectionMessage::class)->handle('community-preview', $section, 'ru'),
        );

        self::assertStringContainsString('https://communities.example.test/one', $preview['bodyHtml']);
        self::assertStringNotContainsString('javascript:', strtolower($preview['bodyHtml']));
        self::assertStringNotContainsString('data:text', strtolower($preview['bodyHtml']));
        self::assertStringNotContainsString('vbscript:', strtolower($preview['bodyHtml']));
        self::assertSame('https://cdn.example.test/communities.jpg', $preview['mediaUrl']);
    }

    public function test_existing_author_method_and_partner_section_routes_remain_available(): void
    {
        [$organization] = $this->organizationAndAdmin();
        config()->set('tenancy.default_organization_id', $organization->getKey());

        foreach (['author', 'method', 'partner'] as $section) {
            $this->withSession(['portal.locale' => 'ru'])
                ->get(route('portal.section', ['section' => $section]))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                    ->component('Portal/Section')
                    ->where('title', config("portal.content_sections.{$section}.title.ru")));
        }
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationAndAdmin(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);

        return [$organization, $admin];
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }

    /** @return array{0: Organization, 1: User} */
    private function filamentOrganizationAndAdmin(): array
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return [$organization, $admin];
    }

    private function createContentSectionPage(): CreateContentSectionPage
    {
        $component = Testable::create(CreateContentSectionPage::class)->instance();
        self::assertInstanceOf(CreateContentSectionPage::class, $component);

        return $component;
    }

    /**
     * @param  list<string>  $labels
     * @param  list<string>  $links
     * @return array<string, mixed>
     */
    private function filamentRichEditorLinkState(array $labels, array $links): array
    {
        $content = [];

        foreach ($labels as $index => $label) {
            $content[] = [
                'type' => 'paragraph',
                'attrs' => [
                    'textAlign' => 'start',
                ],
                'content' => [[
                    'type' => 'text',
                    'text' => $label,
                    'marks' => [[
                        'type' => 'link',
                        'attrs' => [
                            'href' => $links[$index],
                            'target' => null,
                            'rel' => null,
                            'class' => null,
                            'title' => null,
                        ],
                    ]],
                ]],
            ];
        }

        return ['type' => 'doc', 'content' => $content];
    }

    private function fakeNutgram(): FakeNutgram
    {
        $bot = app(Nutgram::class);

        if (! $bot instanceof FakeNutgram) {
            throw new \LogicException('The test bot is not a FakeNutgram instance.');
        }

        return $bot;
    }
}
