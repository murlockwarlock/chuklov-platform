<?php

namespace Tests\Feature;

use App\Filament\Resources\ContentSections\Pages\EditContentSection;
use App\Filament\Resources\ContentSections\Pages\ViewContentSection;
use App\Filament\Resources\ContentSections\Schemas\ContentSectionForm;
use App\Filament\Resources\ScenarioRules\Schemas\ScenarioRuleForm;
use App\Filament\Resources\SurveyDefinitions\Schemas\SurveyDefinitionForm;
use App\Models\User;
use App\Modules\Channels\Application\BuildTelegramContentSectionMessage;
use App\Modules\Channels\Application\GetTelegramMenu;
use App\Modules\Channels\Application\TelegramMessagePreview;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Content\Application\ContentImageUrlResolver;
use App\Modules\Content\Application\CreateContentSection;
use App\Modules\Content\Application\ListPublishedContentSections;
use App\Modules\Content\Application\UpdateContentSection;
use App\Modules\Content\Domain\Enums\ContentDeliveryMode;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Image as SchemaImage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

final class ContentSectionMediaTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'content-media-test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('content_media.disk', self::DISK);
        config()->set('content_media.max_bytes', 5_242_880);
        Storage::fake(self::DISK);
    }

    public function test_requested_form_sections_are_full_width(): void
    {
        foreach ([
            SurveyDefinitionForm::configure(Schema::make()),
            ContentSectionForm::configure(Schema::make()),
            ScenarioRuleForm::configure(Schema::make()),
        ] as $schema) {
            foreach ($schema->getComponents() as $component) {
                self::assertInstanceOf(Section::class, $component);
                self::assertSame('full', $component->getColumnSpan('default'));
            }
        }
    }

    public function test_content_delivery_mode_filters_the_same_canonical_section_for_each_channel(): void
    {
        [$organization] = $this->organizationAndAdmin();
        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'delivery_mode' => ContentDeliveryMode::Telegram,
        ]);
        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'method',
            'locale' => 'ru',
            'delivery_mode' => ContentDeliveryMode::MiniApp,
        ]);
        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'partner',
            'locale' => 'ru',
            'delivery_mode' => ContentDeliveryMode::Both,
        ]);

        $sections = app(ListPublishedContentSections::class);

        self::assertCount(1, $sections->handle('author', ContentDeliveryMode::Telegram));
        self::assertCount(0, $sections->handle('author', ContentDeliveryMode::MiniApp));
        self::assertCount(0, $sections->handle('method', ContentDeliveryMode::Telegram));
        self::assertCount(1, $sections->handle('method', ContentDeliveryMode::MiniApp));
        self::assertCount(1, $sections->handle('partner', ContentDeliveryMode::Telegram));
        self::assertCount(1, $sections->handle('partner', ContentDeliveryMode::MiniApp));
    }

    public function test_edit_form_shows_current_image_preview_and_clear_control(): void
    {
        [$organization, $admin] = $this->filamentOrganizationAndAdmin();
        $image = 'https://cdn.example.test/content/current.jpg';
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'media' => ['image' => $image, 'alt' => 'Текущее изображение'],
        ]);

        $component = Livewire::actingAs($admin)
            ->test(EditContentSection::class, ['record' => $section->getKey()])
            ->assertSuccessful()
            ->assertSee('Удалить текущее изображение');
        $preview = collect($component->instance()->getSchema('form')?->getFlatComponents(withHidden: true))
            ->first(fn (mixed $schemaComponent): bool => $schemaComponent instanceof SchemaImage);

        self::assertInstanceOf(SchemaImage::class, $preview);
        self::assertSame($image, $preview->getUrl());
        self::assertSame('Текущее изображение', $preview->getAlt());
    }

    public function test_content_section_view_displays_image_status_for_a_stored_image(): void
    {
        [$organization, $admin] = $this->filamentOrganizationAndAdmin();
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'media' => ['image' => 'https://cdn.example.test/content/current.jpg'],
        ]);

        Livewire::actingAs($admin)
            ->test(ViewContentSection::class, ['record' => $section->getKey()])
            ->assertSuccessful()
            ->assertSee('Добавлено');
    }

    public function test_content_form_preview_and_remove_actions_use_current_form_state(): void
    {
        [$organization, $admin] = $this->filamentOrganizationAndAdmin();
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Об академии',
            'body' => '<p>Текст раздела</p>',
            'media' => ['image' => 'https://cdn.example.test/content/current.jpg'],
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(EditContentSection::class, ['record' => $section->getKey()])
            ->assertSuccessful();
        $schema = $component->instance()->getSchema('form');
        $preview = collect($schema?->getFlatComponents(withHidden: true))
            ->first(fn (mixed $schemaComponent): bool => $schemaComponent instanceof SchemaImage);
        $previewAction = $schema?->getAction('telegramPreview');
        $removeAction = $schema?->getAction('removeImage');

        self::assertInstanceOf(SchemaImage::class, $preview);
        self::assertSame('Об академии', $preview->getAlt());
        self::assertNotNull($previewAction);
        self::assertNotNull($removeAction);
        self::assertInstanceOf(View::class, $previewAction?->getModalContent());

        $removeAction?->call();

        self::assertTrue((bool) $component->get('data.remove_image'));
    }

    public function test_telegram_menu_keeps_mini_app_only_content_reachable_for_mixed_rows_and_fallbacks(): void
    {
        [$organization] = $this->organizationAndAdmin();
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        $sectionKeys = ['telegram_menu', 'mini_menu', 'both_menu', 'mixed_menu', 'fallback_menu'];
        $contentSections = config('portal.content_sections');
        $entries = config('portal.telegram.entries');
        $menu = config('portal.telegram.menu.en');
        $ruMenu = config('portal.telegram.menu.ru');

        foreach ($sectionKeys as $sectionKey) {
            $contentSections[$sectionKey] = ['title' => ['en' => $sectionKey, 'ru' => $sectionKey]];
            $entries[$sectionKey] = [
                'launch' => 'mini_app',
                'requires_auth' => false,
                'route' => 'portal.section',
                'parameters' => ['section' => $sectionKey],
            ];
            $menu[] = ['key' => $sectionKey, 'label' => $sectionKey];
            $ruMenu[] = ['key' => $sectionKey, 'label' => $sectionKey];
        }

        config()->set('portal.content_sections', $contentSections);
        config()->set('portal.telegram.entries', $entries);
        config()->set('portal.telegram.menu.en', $menu);
        config()->set('portal.telegram.menu.ru', $ruMenu);

        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'telegram_menu',
            'locale' => 'en',
            'delivery_mode' => ContentDeliveryMode::Telegram,
        ]);
        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'mini_menu',
            'locale' => 'en',
            'delivery_mode' => ContentDeliveryMode::MiniApp,
        ]);
        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'both_menu',
            'locale' => 'en',
            'delivery_mode' => ContentDeliveryMode::Both,
        ]);
        foreach ([ContentDeliveryMode::Telegram, ContentDeliveryMode::MiniApp] as $deliveryMode) {
            ContentSection::factory()->forOrganization($organization)->create([
                'section_key' => 'mixed_menu',
                'locale' => 'en',
                'delivery_mode' => $deliveryMode,
            ]);
        }
        ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'fallback_menu',
            'locale' => 'en',
            'delivery_mode' => ContentDeliveryMode::Telegram,
        ]);

        $resolved = collect(app(GetTelegramMenu::class)->handle('ru'))->keyBy('key');

        self::assertSame('telegram_content', $resolved['telegram_menu']['launch']);
        self::assertSame('telegram_content', $resolved['both_menu']['launch']);
        self::assertSame('mini_app', $resolved['mini_menu']['launch']);
        self::assertSame('mini_app', $resolved['mixed_menu']['launch']);
        self::assertSame('telegram_content', $resolved['fallback_menu']['launch']);
        self::assertArrayNotHasKey('callback_data', $resolved['mini_menu']);
        self::assertArrayNotHasKey('callback_data', $resolved['mixed_menu']);
    }

    public function test_both_content_builds_one_telegram_projection_with_image_caption_and_mini_app_cta(): void
    {
        [$organization] = $this->organizationAndAdmin();
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'partner',
            'locale' => 'ru',
            'title' => 'Партнёры',
            'body' => '<p><strong>Текст</strong> 😀 ❤️</p>',
            'delivery_mode' => ContentDeliveryMode::Both,
            'media' => ['image' => 'https://cdn.example.test/partner.jpg', 'alt' => 'Партнёры'],
        ]);

        $message = app(BuildTelegramContentSectionMessage::class)->handle('content-chat', $section, 'ru');
        $preview = app(TelegramMessagePreview::class)->handle($message);

        self::assertSame(NotificationMessageMode::ImageWithCaption, $message->mode);
        self::assertSame('https://cdn.example.test/partner.jpg', $message->mediaUrl);
        self::assertNotNull($message->actionButton);
        self::assertSame('Открыть полностью', $message->actionButton?->text);
        self::assertSame('image_caption', $preview['mode']);
        self::assertStringContainsString('<b>Партнёры</b>', $preview['bodyHtml']);
        self::assertStringContainsString('😀 ❤️', $preview['bodyHtml']);
        self::assertStringNotContainsString('<img', $preview['bodyHtml']);
        self::assertTrue($preview['hasImage']);
        self::assertTrue($preview['hasText']);
        self::assertSame('Открыть полностью', $preview['actionButton']['text'] ?? null);
    }

    public function test_telegram_content_projection_escapes_plain_text_before_adding_the_title(): void
    {
        [$organization] = $this->organizationAndAdmin();
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'partner',
            'locale' => 'ru',
            'title' => 'Партнёры',
            'body' => '5 < 10 & 11 > 3',
            'delivery_mode' => ContentDeliveryMode::Telegram,
        ]);

        $message = app(BuildTelegramContentSectionMessage::class)->handle('content-chat', $section, 'ru');
        $preview = app(TelegramMessagePreview::class)->handle($message);

        self::assertStringContainsString('5 &lt; 10 &amp; 11 &gt; 3', $preview['bodyHtml']);
    }

    public function test_content_section_without_an_image_omits_blank_media_metadata(): void
    {
        [, $admin] = $this->organizationAndAdmin();

        foreach ([null, '', '   '] as $alt) {
            $section = app(CreateContentSection::class)->handle($admin, [
                'section_key' => 'author',
                'locale' => 'ru',
                'title' => 'Без изображения',
                'body' => 'Текст раздела.',
                'media' => ['alt' => $alt],
            ]);

            self::assertNull($section->media);
        }
    }

    public function test_content_image_upload_is_stored_under_the_organization_path(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();

        $section = app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'О нашей академии',
            'body' => 'Текст раздела.',
            'media' => ['alt' => null],
            'content_image' => UploadedFile::fake()->image('academy.webp', 640, 480),
            'sort_order' => 10,
            'is_visible' => true,
        ]);

        $path = $section->media['image'] ?? null;

        self::assertIsString($path);
        self::assertArrayNotHasKey('alt', $section->media ?? []);
        self::assertMatchesRegularExpression(
            '/^content\/'.$organization->id.'\/[0-9a-f-]{36}\.webp$/i',
            $path,
        );
        Storage::disk(self::DISK)->assertExists($path);
        self::assertSame(
            Storage::disk(self::DISK)->url($path),
            app(ContentImageUrlResolver::class)->resolve($section),
        );
    }

    public function test_managed_content_image_is_streamed_for_telegram_delivery(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $section = app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'О нашей академии',
            'body' => 'Текст раздела.',
            'content_image' => UploadedFile::fake()->image('academy.jpg', 640, 480),
            'delivery_mode' => ContentDeliveryMode::Telegram->value,
        ]);
        $path = $this->imagePath($section->media);

        $message = app(BuildTelegramContentSectionMessage::class)->handle(
            'content-chat',
            $section,
            'ru',
            includeMediaStream: true,
        );
        $stream = $message->mediaStream;

        self::assertIsResource($stream);
        self::assertSame(Storage::disk(self::DISK)->get($path), stream_get_contents($stream));
        fclose($stream);
        self::assertNull($message->mediaUrl);

        $previewMessage = app(BuildTelegramContentSectionMessage::class)->handle('content-preview', $section, 'ru');

        self::assertSame(Storage::disk(self::DISK)->url($path), $previewMessage->mediaUrl);
        self::assertNull($previewMessage->mediaStream);
        self::assertSame($organization->getKey(), $section->organization_id);
    }

    public function test_missing_managed_content_image_does_not_fall_back_to_preview_url_for_telegram_delivery(): void
    {
        [, $admin] = $this->organizationAndAdmin();
        $section = app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'О нашей академии',
            'body' => 'Текст раздела.',
            'content_image' => UploadedFile::fake()->image('academy.jpg', 640, 480),
            'delivery_mode' => ContentDeliveryMode::Telegram->value,
        ]);
        $path = $this->imagePath($section->media);
        Storage::disk(self::DISK)->delete($path);

        $message = app(BuildTelegramContentSectionMessage::class)->handle(
            'content-chat',
            $section,
            'ru',
            includeMediaStream: true,
        );

        self::assertNull($message->mediaStream);
        self::assertNull($message->mediaUrl);
        self::assertTrue($message->mode->includesImage());
    }

    public function test_editing_title_and_body_with_blank_url_preserves_managed_image(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $section = app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'До изменения',
            'body' => 'Исходный текст.',
            'content_image' => UploadedFile::fake()->image('preserved.png'),
        ]);
        $path = $this->imagePath($section->media);

        $updated = app(UpdateContentSection::class)->handle($admin, $section, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'После изменения',
            'body' => 'Обновлённый текст.',
            'media' => ['image' => ''],
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        self::assertSame($path, $this->imagePath($updated->media));
        Storage::disk(self::DISK)->assertExists($path);
        self::assertSame($organization->id, $updated->organization_id);
    }

    public function test_editing_only_alt_text_preserves_managed_image(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $section = app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Изображение',
            'body' => 'Текст раздела.',
            'media' => ['alt' => 'Старое описание'],
            'content_image' => UploadedFile::fake()->image('alt.png'),
        ]);
        $path = $this->imagePath($section->media);

        $updated = app(UpdateContentSection::class)->handle($admin, $section, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Изображение',
            'body' => 'Текст раздела.',
            'media' => ['image' => '', 'alt' => 'Новое описание'],
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        self::assertSame($path, $this->imagePath($updated->media));
        self::assertSame('Новое описание', $updated->media['alt'] ?? null);
        Storage::disk(self::DISK)->assertExists($path);
        self::assertSame($organization->id, $updated->organization_id);
    }

    public function test_explicit_remove_deletes_managed_image_after_commit(): void
    {
        [, $admin] = $this->organizationAndAdmin();
        $section = app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Удаляемое изображение',
            'body' => 'Текст раздела.',
            'content_image' => UploadedFile::fake()->image('remove.png'),
        ]);
        $path = $this->imagePath($section->media);

        $updated = app(UpdateContentSection::class)->handle($admin, $section, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Удаляемое изображение',
            'body' => 'Текст раздела.',
            'media' => ['image' => ''],
            'remove_image' => true,
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        self::assertNull($updated->media);
        Storage::disk(self::DISK)->assertMissing($path);
    }

    public function test_invalid_content_image_is_rejected(): void
    {
        [, $admin] = $this->organizationAndAdmin();

        $this->expectException(ValidationException::class);

        app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Неверное изображение',
            'body' => 'Текст раздела.',
            'content_image' => UploadedFile::fake()->createWithContent('not-an-image.jpg', 'plain text'),
        ]);
    }

    public function test_replacing_content_image_removes_the_old_managed_file_after_commit(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $section = app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Изображение',
            'body' => 'Текст раздела.',
            'content_image' => UploadedFile::fake()->image('old.png', 320, 240),
        ]);
        $oldMedia = $section->media;
        self::assertIsArray($oldMedia);
        self::assertArrayHasKey('image', $oldMedia);
        $oldPath = $oldMedia['image'];

        $updated = app(UpdateContentSection::class)->handle($admin, $section, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Изображение обновлено',
            'body' => 'Новый текст раздела.',
            'content_image' => UploadedFile::fake()->image('new.jpg', 640, 480),
            'sort_order' => 0,
            'is_visible' => true,
        ]);
        $newMedia = $updated->media;
        self::assertIsArray($newMedia);
        self::assertArrayHasKey('image', $newMedia);
        $newPath = $newMedia['image'];

        self::assertNotSame($oldPath, $newPath);
        self::assertTrue(str_starts_with($newPath, "content/{$organization->id}/"));
        Storage::disk(self::DISK)->assertMissing($oldPath);
        Storage::disk(self::DISK)->assertExists($newPath);
    }

    public function test_unchanged_legacy_image_value_is_preserved_on_unrelated_edit(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $legacyImage = 'private-reference';
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'До изменения',
            'body' => 'Исходный текст.',
            'media' => ['image' => $legacyImage],
        ]);

        $updated = app(UpdateContentSection::class)->handle($admin, $section, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'После изменения',
            'body' => 'Обновлённый текст.',
            'media' => ['image' => $legacyImage],
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        self::assertSame($legacyImage, $this->imagePath($updated->media));
    }

    public function test_unchanged_legacy_image_value_is_preserved_when_form_field_is_blank(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $legacyImage = 'http://legacy.example.test/content/old.jpg';
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'До изменения',
            'body' => 'Исходный текст.',
            'media' => ['image' => $legacyImage],
        ]);

        $updated = app(UpdateContentSection::class)->handle($admin, $section, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'После изменения',
            'body' => 'Обновлённый текст.',
            'media' => ['image' => ''],
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        self::assertSame($legacyImage, $this->imagePath($updated->media));
    }

    public function test_explicit_remove_clears_an_unchanged_legacy_image_value(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $legacyImage = 'private-reference';
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Удаление',
            'body' => 'Текст раздела.',
            'media' => ['image' => $legacyImage],
        ]);

        $updated = app(UpdateContentSection::class)->handle($admin, $section, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Удаление',
            'body' => 'Текст раздела.',
            'media' => ['image' => $legacyImage],
            'remove_image' => true,
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        self::assertNull($updated->media);
    }

    public function test_new_invalid_external_image_values_replace_legacy_image_only_by_rejection(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'До изменения',
            'body' => 'Исходный текст.',
            'media' => ['image' => 'private-reference'],
        ]);

        foreach ([
            'http://new.example.test/content/image.jpg',
            'new-private-reference',
        ] as $newImage) {
            try {
                app(UpdateContentSection::class)->handle($admin, $section, [
                    'section_key' => 'author',
                    'locale' => 'ru',
                    'title' => 'После изменения',
                    'body' => 'Обновлённый текст.',
                    'media' => ['image' => $newImage],
                    'sort_order' => 0,
                    'is_visible' => true,
                ]);
                self::fail("URL should be rejected: {$newImage}");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame('private-reference', $this->imagePath($section->refresh()->media));
    }

    public function test_new_valid_https_image_value_replaces_legacy_image(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $legacyImage = 'private-reference';
        $newImage = 'https://cdn.example.test/content/new.jpg';
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'До изменения',
            'body' => 'Исходный текст.',
            'media' => ['image' => $legacyImage],
        ]);

        $updated = app(UpdateContentSection::class)->handle($admin, $section, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'После изменения',
            'body' => 'Обновлённый текст.',
            'media' => ['image' => $newImage],
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        self::assertSame($newImage, $this->imagePath($updated->media));
    }

    public function test_stale_caller_preserves_image_from_locked_authoritative_state(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'До изменения',
            'body' => 'Исходный текст.',
            'media' => null,
        ]);
        $staleSection = ContentSection::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($section->getKey())
            ->firstOrFail();
        $authoritativeImage = 'authoritative-reference';
        $authoritativeSection = ContentSection::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($section->getKey())
            ->firstOrFail();
        $authoritativeSection->forceFill(['media' => ['image' => $authoritativeImage]])->save();

        $updated = app(UpdateContentSection::class)->handle($admin, $staleSection, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'После изменения',
            'body' => 'Обновлённый текст.',
            'media' => ['image' => ''],
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        self::assertSame($authoritativeImage, $this->imagePath($updated->media));
    }

    public function test_managed_image_can_be_replaced_with_a_valid_https_url(): void
    {
        [, $admin] = $this->organizationAndAdmin();
        $section = app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Внешняя ссылка',
            'body' => 'Текст раздела.',
            'content_image' => UploadedFile::fake()->image('old.jpg'),
        ]);
        $oldPath = $this->imagePath($section->media);
        $url = 'https://cdn.example.test/content/author.jpg';

        $updated = app(UpdateContentSection::class)->handle($admin, $section, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Внешняя ссылка',
            'body' => 'Текст раздела.',
            'media' => ['image' => $url],
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        self::assertSame($url, $this->imagePath($updated->media));
        Storage::disk(self::DISK)->assertMissing($oldPath);
    }

    public function test_existing_external_url_is_preserved_on_unrelated_edit(): void
    {
        [, $admin] = $this->organizationAndAdmin();
        $url = 'https://cdn.example.test/content/existing.jpg';
        $section = app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'До изменения',
            'body' => 'Исходный текст.',
            'media' => ['image' => $url],
        ]);

        $updated = app(UpdateContentSection::class)->handle($admin, $section, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'После изменения',
            'body' => 'Обновлённый текст.',
            'media' => ['image' => $url],
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        self::assertSame($url, $this->imagePath($updated->media));
    }

    public function test_update_without_alt_preserves_existing_stored_alt(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        $image = 'https://cdn.example.test/content/with-alt.jpg';
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'До изменения',
            'body' => 'Исходный текст.',
            'media' => ['image' => $image, 'alt' => 'Старое описание'],
        ]);

        $updated = app(UpdateContentSection::class)->handle($admin, $section, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'После изменения',
            'body' => 'Обновлённый текст.',
            'media' => ['image' => $image],
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        self::assertSame($image, $this->imagePath($updated->media));
        self::assertSame('Старое описание', $updated->media['alt'] ?? null);
    }

    public function test_existing_external_url_is_preserved_when_url_field_is_blank(): void
    {
        [, $admin] = $this->organizationAndAdmin();
        $url = 'https://cdn.example.test/content/blank.jpg';
        $section = app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'До изменения',
            'body' => 'Исходный текст.',
            'media' => ['image' => $url],
        ]);

        $updated = app(UpdateContentSection::class)->handle($admin, $section, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'После изменения',
            'body' => 'Обновлённый текст.',
            'media' => ['image' => ''],
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        self::assertSame($url, $this->imagePath($updated->media));
    }

    public function test_explicit_remove_clears_existing_external_url(): void
    {
        [, $admin] = $this->organizationAndAdmin();
        $url = 'https://cdn.example.test/content/remove.jpg';
        $section = app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Удаляемая ссылка',
            'body' => 'Текст раздела.',
            'media' => ['image' => $url],
        ]);

        $updated = app(UpdateContentSection::class)->handle($admin, $section, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Удаляемая ссылка',
            'body' => 'Текст раздела.',
            'media' => ['image' => $url],
            'remove_image' => true,
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        self::assertNull($updated->media);
    }

    public function test_valid_https_external_image_url_is_accepted_at_application_boundary(): void
    {
        [, $admin] = $this->organizationAndAdmin();
        $url = 'https://cdn.example.test/content/valid.jpg';

        $section = app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Валидная ссылка',
            'body' => 'Текст раздела.',
            'media' => ['image' => $url, 'alt' => null],
        ]);

        self::assertSame($url, $this->imagePath($section->media));
        self::assertArrayNotHasKey('alt', $section->media ?? []);
    }

    public function test_non_string_content_media_metadata_fails_closed(): void
    {
        [, $admin] = $this->organizationAndAdmin();

        $this->expectException(InvalidArgumentException::class);

        app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Неверные метаданные',
            'body' => 'Текст раздела.',
            'media' => ['alt' => ['не строка']],
        ]);
    }

    public function test_invalid_external_image_urls_are_rejected_at_application_boundary(): void
    {
        [, $admin] = $this->organizationAndAdmin();

        foreach ([
            'http://cdn.example.test/content/image.jpg',
            '/content/image.jpg',
            'javascript:alert(1)',
            'data:image/png;base64,AAAA',
            'file:///tmp/image.jpg',
            'not-a-url',
            'https://',
            'https://cdn.example.test/'.str_repeat('a', 2000),
        ] as $url) {
            try {
                app(CreateContentSection::class)->handle($admin, [
                    'section_key' => 'author',
                    'locale' => 'ru',
                    'title' => 'Невалидная ссылка',
                    'body' => 'Текст раздела.',
                    'media' => ['image' => $url],
                ]);
                self::fail("URL should be rejected: {$url}");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_upload_and_external_url_cannot_be_submitted_together(): void
    {
        [, $admin] = $this->organizationAndAdmin();

        $this->expectException(ValidationException::class);

        app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Два источника изображения',
            'body' => 'Текст раздела.',
            'media' => ['image' => 'https://cdn.example.test/content/image.jpg'],
            'content_image' => UploadedFile::fake()->image('image.jpg'),
        ]);
    }

    public function test_update_is_rejected_for_a_different_organization(): void
    {
        [, $admin] = $this->organizationAndAdmin();
        $section = app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Организация',
            'body' => 'Текст раздела.',
            'content_image' => UploadedFile::fake()->image('image.jpg'),
        ]);
        [, $otherAdmin] = $this->organizationAndAdmin();

        $this->expectException(AuthorizationException::class);

        app(UpdateContentSection::class)->handle($otherAdmin, $section, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Чужая организация',
            'body' => 'Изменение запрещено.',
            'media' => ['image' => ''],
            'sort_order' => 0,
            'is_visible' => true,
        ]);
    }

    public function test_filament_hides_legacy_image_and_technical_alt_field_on_unrelated_edit(): void
    {
        [$organization, $admin] = $this->filamentOrganizationAndAdmin();
        $legacyImage = 'private-reference';
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'До изменения',
            'body' => 'Исходный текст.',
            'media' => ['image' => $legacyImage],
        ]);

        $component = Livewire::actingAs($admin)
            ->test(EditContentSection::class, ['record' => $section->getKey()]);
        $state = $component->get('data');

        self::assertIsArray($state);
        self::assertNull($state['media']['image'] ?? null);
        $component->assertFormFieldDoesNotExist('media.alt');

        $component
            ->fillForm([
                'title' => 'После изменения',
                'body' => 'Обновлённый текст.',
                'remove_image' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        self::assertSame($legacyImage, $this->imagePath($section->refresh()->media));
        self::assertArrayNotHasKey('alt', $section->media ?? []);
    }

    public function test_filament_can_explicitly_remove_a_legacy_image(): void
    {
        [$organization, $admin] = $this->filamentOrganizationAndAdmin();
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Удаление',
            'body' => 'Текст раздела.',
            'media' => ['image' => 'private-reference'],
        ]);

        Livewire::actingAs($admin)
            ->test(EditContentSection::class, ['record' => $section->getKey()])
            ->fillForm(['remove_image' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        self::assertNull($section->refresh()->media);
    }

    public function test_filament_replaces_a_legacy_image_with_a_valid_https_url(): void
    {
        [$organization, $admin] = $this->filamentOrganizationAndAdmin();
        $newImage = 'https://cdn.example.test/content/replaced.jpg';
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Замена',
            'body' => 'Текст раздела.',
            'media' => ['image' => 'private-reference'],
        ]);

        Livewire::actingAs($admin)
            ->test(EditContentSection::class, ['record' => $section->getKey()])
            ->fillForm([
                'media' => ['image' => $newImage],
                'remove_image' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        self::assertSame($newImage, $this->imagePath($section->refresh()->media));
    }

    public function test_filament_preserves_stored_image_alt_without_exposing_the_technical_field(): void
    {
        [$organization, $admin] = $this->filamentOrganizationAndAdmin();
        $image = 'https://cdn.example.test/content/with-alt.jpg';
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Описание изображения',
            'body' => 'Текст раздела.',
            'media' => [
                'image' => $image,
                'alt' => 'Старое описание',
            ],
        ]);

        $component = Livewire::actingAs($admin)
            ->test(EditContentSection::class, ['record' => $section->getKey()]);

        $component
            ->assertFormFieldDoesNotExist('media.alt')
            ->fillForm(['remove_image' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $media = $section->refresh()->media;

        self::assertIsArray($media);
        self::assertSame($image, $this->imagePath($media));
        self::assertSame('Старое описание', $media['alt'] ?? null);
    }

    public function test_filament_rejects_an_invalid_replacement_without_clearing_a_legacy_image(): void
    {
        [$organization, $admin] = $this->filamentOrganizationAndAdmin();
        $legacyImage = 'private-reference';
        $section = ContentSection::factory()->forOrganization($organization)->create([
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'Неверная замена',
            'body' => 'Текст раздела.',
            'media' => ['image' => $legacyImage],
        ]);

        Livewire::actingAs($admin)
            ->test(EditContentSection::class, ['record' => $section->getKey()])
            ->fillForm([
                'media' => ['image' => 'http://cdn.example.test/content/rejected.jpg'],
                'remove_image' => false,
            ])
            ->call('save')
            ->assertHasErrors();

        self::assertSame($legacyImage, $this->imagePath($section->refresh()->media));
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationAndAdmin(): array
    {
        $organization = Organization::factory()->create();
        self::assertInstanceOf(Organization::class, $organization);
        $admin = User::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }

    /** @return array{0: Organization, 1: User} */
    private function filamentOrganizationAndAdmin(): array
    {
        [$organization, $admin] = $this->organizationAndAdmin();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return [$organization, $admin];
    }

    /** @param array<string, string>|null $media */
    private function imagePath(?array $media): string
    {
        $image = $media['image'] ?? null;

        self::assertIsString($image);

        return $image;
    }
}
