<?php

namespace Tests\Feature;

use App\Filament\Resources\ContentSections\Schemas\ContentSectionForm;
use App\Filament\Resources\ScenarioRules\Schemas\ScenarioRuleForm;
use App\Filament\Resources\SurveyDefinitions\Schemas\SurveyDefinitionForm;
use App\Models\User;
use App\Modules\Content\Application\ContentImageUrlResolver;
use App\Modules\Content\Application\CreateContentSection;
use App\Modules\Content\Application\UpdateContentSection;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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

    /** @return array{0: Organization, 1: User} */
    private function organizationAndAdmin(): array
    {
        $organization = Organization::factory()->create();
        self::assertInstanceOf(Organization::class, $organization);
        $admin = User::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }
}
