<?php

namespace Tests\Feature;

use App\Filament\Resources\ContentSections\Pages\EditContentSection;
use App\Filament\Resources\ContentSections\Schemas\ContentSectionForm;
use App\Filament\Resources\ScenarioRules\Schemas\ScenarioRuleForm;
use App\Filament\Resources\SurveyDefinitions\Schemas\SurveyDefinitionForm;
use App\Models\User;
use App\Modules\Content\Application\ContentImageUrlResolver;
use App\Modules\Content\Application\CreateContentSection;
use App\Modules\Content\Application\UpdateContentSection;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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

    public function test_content_image_upload_is_stored_under_the_organization_path(): void
    {
        [$organization, $admin] = $this->organizationAndAdmin();

        $section = app(CreateContentSection::class)->handle($admin, [
            'section_key' => 'author',
            'locale' => 'ru',
            'title' => 'О нашей академии',
            'body' => 'Текст раздела.',
            'content_image' => UploadedFile::fake()->image('academy.webp', 640, 480),
            'sort_order' => 10,
            'is_visible' => true,
        ]);

        $path = $section->media['image'] ?? null;

        self::assertIsString($path);
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
            'media' => ['image' => $url],
        ]);

        self::assertSame($url, $this->imagePath($section->media));
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

    public function test_filament_hides_legacy_image_and_preserves_it_on_unrelated_edit(): void
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

        $component
            ->fillForm([
                'title' => 'После изменения',
                'body' => 'Обновлённый текст.',
                'media' => ['alt' => 'Описание legacy-изображения'],
                'remove_image' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        self::assertSame($legacyImage, $this->imagePath($section->refresh()->media));
        self::assertSame('Описание legacy-изображения', $section->media['alt'] ?? null);
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

    public function test_filament_can_clear_image_alt_without_removing_the_image(): void
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

        Livewire::actingAs($admin)
            ->test(EditContentSection::class, ['record' => $section->getKey()])
            ->fillForm([
                'media' => ['alt' => ''],
                'remove_image' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $media = $section->refresh()->media;

        self::assertIsArray($media);
        self::assertSame($image, $this->imagePath($media));
        self::assertArrayNotHasKey('alt', $media);
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
