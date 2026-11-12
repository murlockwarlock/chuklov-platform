<?php

namespace Tests\Feature;

use App\Filament\Resources\Specialists\Pages\CreateSpecialist as CreateSpecialistPage;
use App\Filament\Resources\Specialists\Pages\EditSpecialist as EditSpecialistPage;
use App\Models\User;
use App\Modules\ClientPortal\Application\ProjectPortalSpecialist;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Application\CreateSpecialist;
use App\Modules\Specialists\Application\UpdateSpecialist;
use App\Modules\Specialists\Domain\Contracts\SpecialistMediaStorageInterface;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

final class SpecialistProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::create(2026, 6, 1, 0, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_application_profile_create_edit_reload_and_photo_lifecycle(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);
        Storage::fake('public');

        $created = app(CreateSpecialist::class)->handle(
            actor: $admin,
            displayName: 'Алия Садыкова',
            isActive: true,
            timezone: 'Asia/Almaty',
            profileAttributes: [
                'specialization' => 'Реабилитолог',
                'practices_since_year' => 2014,
                'qualification' => 'Сертификаты по восстановительной работе.',
                'bio' => str_repeat('Описание опыта и подхода специалиста. ', 30),
                'country' => 'Казахстан',
                'city' => 'Алматы',
                'phone' => '+77001234567',
                'email' => 'aliya@example.com',
                'telegram' => '@aliya_rehab',
                'profile_image' => UploadedFile::fake()->image('aliya.jpg'),
            ],
        );

        $created = $created->fresh();
        self::assertNotNull($created);
        self::assertSame('Алия Садыкова', $created->display_name);
        self::assertSame('Реабилитолог', $created->specialization);
        self::assertSame(2014, $created->practices_since_year);
        self::assertSame('Алматы', $created->city);
        self::assertSame('+77001234567', $created->phone);
        self::assertFalse($created->phone_visible);
        self::assertFalse($created->email_visible);
        self::assertFalse($created->telegram_visible);
        self::assertNotNull($created->profile_image_path);
        Storage::disk('public')->assertExists((string) $created->profile_image_path);

        $oldImagePath = (string) $created->profile_image_path;
        $updated = app(UpdateSpecialist::class)->handle(
            actor: $admin,
            specialist: $created,
            displayName: 'Алия Садыкова',
            isActive: true,
            timezone: 'Asia/Almaty',
            profileAttributes: [
                'specialization' => 'Массажист, специалист по восстановлению',
                'practices_since_year' => 2013,
                'qualification' => 'Обновленная квалификация.',
                'bio' => 'Обновленное описание профиля.',
                'country' => 'Казахстан',
                'city' => 'Астана',
                'phone' => '+77001234567',
                'email' => 'aliya@example.com',
                'telegram' => 'https://t.me/aliya_rehab',
                'phone_visible' => true,
                'email_visible' => false,
                'telegram_visible' => true,
                'profile_image' => UploadedFile::fake()->image('aliya-replacement.png'),
            ],
        );

        $updated = $updated->fresh();
        self::assertNotNull($updated);
        self::assertSame('Астана', $updated->city);
        self::assertSame('@aliya_rehab', $updated->telegram);
        self::assertTrue($updated->phone_visible);
        self::assertTrue($updated->telegram_visible);
        self::assertNotSame($oldImagePath, $updated->profile_image_path);
        Storage::disk('public')->assertMissing($oldImagePath);
        Storage::disk('public')->assertExists((string) $updated->profile_image_path);

        $removed = app(UpdateSpecialist::class)->handle(
            actor: $admin,
            specialist: $updated,
            displayName: $updated->display_name,
            isActive: true,
            timezone: $updated->timezone,
            profileAttributes: ['remove_profile_image' => true],
        )->fresh();

        self::assertNotNull($removed);
        self::assertNull($removed->profile_image_path);
        Storage::disk('public')->assertMissing((string) $updated->profile_image_path);
    }

    public function test_crm_profile_sections_create_edit_and_reload(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = $this->createSpecialistPage($admin)
            ->assertFormFieldVisible('profile_image')
            ->assertFormFieldVisible('specialization')
            ->assertFormFieldVisible('practices_since_year')
            ->assertFormFieldVisible('qualification')
            ->assertFormFieldVisible('bio')
            ->assertFormFieldVisible('phone_visible')
            ->assertFormFieldVisible('timezone')
            ->assertFormFieldVisible('staff_user_id')
            ->fillForm([
                'display_name' => 'Марина Орлова',
                'specialization' => 'Специалист по восстановлению',
                'practices_since_year' => 2018,
                'qualification' => 'Профессиональная квалификация.',
                'bio' => 'Описание подхода к работе.',
                'city' => 'Алматы',
                'email' => 'marina@example.com',
                'telegram' => '@marina_work',
                'is_active' => true,
                'notifications_enabled' => true,
                'phone_visible' => false,
                'email_visible' => false,
                'telegram_visible' => false,
            ])
            ->call('create')
            ->assertHasNoErrors();

        $specialist = Specialist::query()->where('display_name', 'Марина Орлова')->sole();
        self::assertSame('Специалист по восстановлению', $specialist->specialization);

        $this->editSpecialistPage($admin, $specialist)
            ->fillForm([
                'display_name' => 'Марина Орлова',
                'specialization' => 'Новая специализация',
                'practices_since_year' => 2017,
                'qualification' => 'Обновленная квалификация.',
                'bio' => 'Обновленное описание.',
                'city' => 'Астана',
                'email' => 'marina@example.com',
                'telegram' => '@marina_work',
                'is_active' => true,
                'notifications_enabled' => true,
                'phone_visible' => false,
                'email_visible' => true,
                'telegram_visible' => false,
            ])
            ->call('save')
            ->assertHasNoErrors();

        $reloaded = $specialist->fresh();
        self::assertNotNull($reloaded);
        self::assertSame('Новая специализация', $reloaded->specialization);
        self::assertSame('Астана', $reloaded->city);
        self::assertTrue($reloaded->email_visible);
    }

    public function test_crm_create_validation_preserves_form_state_and_allows_phone_correction(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = $this->createSpecialistPage($admin)
            ->fillForm([
                'display_name' => 'Сергей Воронцов',
                'specialization' => 'Реабилитолог',
                'practices_since_year' => 2014,
                'qualification' => 'Квалификация специалиста.',
                'bio' => 'Описание подхода специалиста.',
                'country' => 'KZ',
                'city' => 'Алматы',
                'phone' => 'не телефон',
                'email' => 'sergey@example.com',
                'telegram' => '@sergey_work',
                'is_active' => true,
                'notifications_enabled' => true,
            ])
            ->call('create')
            ->assertHasErrors(['data.phone'])
            ->assertNotified('Не удалось сохранить специалиста. Исправьте отмеченные поля.')
            ->assertSet('data.specialization', 'Реабилитолог')
            ->assertSet('data.qualification', 'Квалификация специалиста.')
            ->assertSet('data.bio', 'Описание подхода специалиста.')
            ->assertSet('data.phone', 'не телефон');

        self::assertDatabaseMissing('specialists', ['display_name' => 'Сергей Воронцов']);

        $component
            ->fillForm(['phone' => '+77001234567'])
            ->call('create')
            ->assertHasNoErrors()
            ->assertNotified('Изменения сохранены');

        $specialist = Specialist::query()->where('display_name', 'Сергей Воронцов')->sole();
        self::assertSame('+77001234567', $specialist->phone);
        self::assertSame('Реабилитолог', $specialist->specialization);
        self::assertSame('Описание подхода специалиста.', $specialist->bio);
        self::assertSame('Алматы', $specialist->city);
    }

    public function test_crm_edit_validation_preserves_form_state_and_allows_telegram_correction(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $specialist = app(CreateSpecialist::class)->handle(
            actor: $admin,
            displayName: 'Ольга Белова',
            profileAttributes: ['specialization' => 'Массажист'],
        );

        $component = $this->editSpecialistPage($admin, $specialist)
            ->fillForm([
                'display_name' => 'Ольга Белова',
                'specialization' => 'Массажист по восстановлению',
                'qualification' => 'Обновленная квалификация.',
                'bio' => 'Обновленное описание подхода.',
                'country' => 'KZ',
                'city' => 'Астана',
                'phone' => '+77001234567',
                'email' => 'olga@example.com',
                'telegram' => 'не Telegram',
                'is_active' => true,
                'notifications_enabled' => true,
            ])
            ->call('save')
            ->assertHasErrors(['data.telegram'])
            ->assertNotified('Не удалось сохранить специалиста. Исправьте отмеченные поля.')
            ->assertSet('data.specialization', 'Массажист по восстановлению')
            ->assertSet('data.qualification', 'Обновленная квалификация.')
            ->assertSet('data.bio', 'Обновленное описание подхода.')
            ->assertSet('data.telegram', 'не Telegram');

        $afterInvalid = $specialist->fresh();
        self::assertNotNull($afterInvalid);
        self::assertSame('Массажист', $afterInvalid->specialization);

        $component
            ->fillForm(['telegram' => '@olga_work'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified('Изменения сохранены');

        $reloaded = $specialist->fresh();
        self::assertNotNull($reloaded);
        self::assertSame('Массажист по восстановлению', $reloaded->specialization);
        self::assertSame('@olga_work', $reloaded->telegram);
        self::assertSame('Астана', $reloaded->city);
    }

    public function test_crm_builtin_email_validation_keeps_state_and_shows_save_error(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = $this->createSpecialistPage($admin)
            ->fillForm([
                'display_name' => 'Ирина Крылова',
                'specialization' => 'Реабилитолог',
                'email' => 'not-an-email',
                'is_active' => true,
                'notifications_enabled' => true,
            ])
            ->call('create')
            ->assertHasErrors(['data.email'])
            ->assertNotified('Не удалось сохранить специалиста. Исправьте отмеченные поля.')
            ->assertSet('data.display_name', 'Ирина Крылова')
            ->assertSet('data.specialization', 'Реабилитолог')
            ->assertSet('data.email', 'not-an-email');

        self::assertDatabaseMissing('specialists', ['display_name' => 'Ирина Крылова']);
    }

    public function test_crm_profile_image_storage_validation_maps_to_the_image_field(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        app()->instance(SpecialistMediaStorageInterface::class, new class implements SpecialistMediaStorageInterface
        {
            public function store(int $organizationId, UploadedFile $file): string
            {
                throw ValidationException::withMessages([
                    'profile_image' => ['Не удалось прочитать фотографию. Попробуйте другой файл.'],
                ]);
            }

            public function isManagedPath(int $organizationId, ?string $path): bool
            {
                return false;
            }

            public function url(string $path): string
            {
                return '';
            }

            public function deleteManaged(int $organizationId, string $path): void {}
        });

        $this->createSpecialistPage($admin)
            ->fillForm([
                'display_name' => 'Андрей Лебедев',
                'specialization' => 'Массажист',
                'profile_image' => UploadedFile::fake()->image('andrey.jpg'),
                'is_active' => true,
                'notifications_enabled' => true,
            ])
            ->call('create')
            ->assertHasErrors(['data.profile_image'])
            ->assertNotified('Не удалось сохранить специалиста. Исправьте отмеченные поля.')
            ->assertSet('data.display_name', 'Андрей Лебедев');

        self::assertDatabaseMissing('specialists', ['display_name' => 'Андрей Лебедев']);
    }

    public function test_public_projection_contains_only_allowed_contacts_and_active_assigned_services(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC', 'language' => 'ru']);
        $this->setOrganization($organization);
        $this->enableServiceCatalog($organization);
        $service = Service::factory()->forOrganization($organization)->create(['name' => 'Восстановительная консультация']);
        $inactiveService = Service::factory()->forOrganization($organization)->inactive()->create(['name' => 'Архивная услуга']);
        $specialist = app(CreateSpecialist::class)->handle(
            actor: $admin,
            displayName: 'Наталья Ким',
            profileAttributes: [
                'specialization' => 'Реабилитолог',
                'practices_since_year' => 2014,
                'qualification' => 'Квалификация владельца клиники.',
                'bio' => 'Описание подхода специалиста.',
                'country' => 'Казахстан',
                'city' => 'Алматы',
                'phone' => '+77001234567',
                'email' => 'natalia@example.com',
                'telegram' => '@natalia_work',
            ],
        );
        app(AssignSpecialistToService::class)->handle($admin, $specialist, $service);
        SpecialistServiceAssignment::factory()->forSpecialist($specialist)->forService($inactiveService)->create();

        $response = $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.specialists.show', [
                'specialist' => $specialist->getKey(),
                'service_id' => $service->getKey(),
            ]));

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/SpecialistShow')
                ->where('specialist.displayName', 'Наталья Ким')
                ->where('specialist.experienceLabel', '12 лет опыта')
                ->where('specialist.contacts', [])
                ->has('specialist.services', 1)
                ->where('specialist.services.0.id', $service->getKey())
                ->where('context.serviceId', $service->getKey())
                ->missing('specialist.phone')
                ->missing('specialist.email')
                ->missing('specialist.telegram')
                ->missing('specialist.staff_user_id')
                ->missing('specialist.notifications_enabled'));

        app(UpdateSpecialist::class)->handle(
            actor: $admin,
            specialist: $specialist,
            displayName: $specialist->display_name,
            isActive: true,
            profileAttributes: [
                'phone_visible' => true,
                'email_visible' => true,
                'telegram_visible' => true,
            ],
        );

        $publicResponse = $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.specialists.show', ['specialist' => $specialist->getKey()]));

        $publicResponse->assertInertia(fn (AssertableInertia $page) => $page
            ->has('specialist.contacts', 3)
            ->where('specialist.contacts.0.value', '+77001234567')
            ->where('specialist.contacts.1.value', 'natalia@example.com')
            ->where('specialist.contacts.2.value', '@natalia_work'));

        $specialist->forceFill(['is_active' => false])->save();
        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.specialists.show', ['specialist' => $specialist->getKey()]))
            ->assertNotFound();
    }

    public function test_booking_projection_uses_profile_card_and_excludes_inactive_specialists(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $this->setOrganization($organization);
        $this->enableServiceCatalog($organization);
        $service = Service::factory()->forOrganization($organization)->create(['name' => 'Массаж']);
        $active = app(CreateSpecialist::class)->handle(
            actor: $admin,
            displayName: 'Ирина Белова',
            profileAttributes: [
                'specialization' => 'Массажист',
                'practices_since_year' => 2021,
                'qualification' => 'Короткие регалии.',
                'city' => 'Алматы',
            ],
        );
        $inactive = Specialist::factory()->forOrganization($organization)->inactive()->create([
            'display_name' => 'Скрытый специалист',
            'specialization' => 'Не показывать',
        ]);
        app(AssignSpecialistToService::class)->handle($admin, $active, $service);
        SpecialistServiceAssignment::factory()->forSpecialist($inactive)->forService($service)->create();

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.bookings.create', ['service_id' => $service->getKey()]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/BookingCreate')
                ->has('specialists', 1)
                ->where('specialists.0.displayName', 'Ирина Белова')
                ->where('specialists.0.specialization', 'Массажист')
                ->where('specialists.0.city', 'Алматы')
                ->where('specialists.0.detailsUrl', route('portal.specialists.show', [
                    'specialist' => $active->getKey(),
                    'service_id' => $service->getKey(),
                ])));
    }

    public function test_specialist_profile_and_media_are_tenant_scoped(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $otherSpecialist = Specialist::factory()->forOrganization($otherOrganization)->create([
            'profile_image_path' => 'specialists/'.$otherOrganization->getKey().'/11111111-1111-4111-8111-111111111111.jpg',
        ]);
        $client = Client::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.specialists.show', ['specialist' => $otherSpecialist->getKey()]))
            ->assertNotFound();

        self::assertNull(app(ProjectPortalSpecialist::class)->compact(
            Specialist::factory()->forOrganization($organization)->create(),
            'ru',
            route('portal.specialists.show', ['specialist' => $otherSpecialist->getKey()]),
        )['imageUrl']);
        self::assertSame($organization->getKey(), $admin->memberships()->sole()->organization_id);
    }

    public function test_specialist_media_adapter_converts_heic_when_runtime_supports_the_codec(): void
    {
        $fixturePath = base_path('tests/Fixtures/images/known-good-heic.heic');
        self::assertFileExists($fixturePath);
        $this->skipIfHeifFixtureCannotBeDecoded($fixturePath, 'HEIC');

        Storage::fake('public');
        $contents = file_get_contents($fixturePath);
        self::assertIsString($contents);

        $storedPath = app(SpecialistMediaStorageInterface::class)->store(
            99,
            UploadedFile::fake()->createWithContent('phone.heic', $contents),
        );
        self::assertStringEndsWith('.webp', $storedPath);
        Storage::disk('public')->assertExists($storedPath);
        $storedContents = Storage::disk('public')->get($storedPath);
        self::assertIsString($storedContents);
        self::assertSame('image/webp', getimagesizefromstring($storedContents)['mime'] ?? null);
    }

    private function skipIfHeifFixtureCannotBeDecoded(string $fixturePath, string $format): void
    {
        if (! extension_loaded('imagick')) {
            self::markTestSkipped("The runtime cannot decode the known-good {$format} fixture because Imagick is unavailable.");
        }

        $decoded = false;
        $probe = new \Imagick;

        try {
            $probe->readImage($fixturePath);
            $decoded = $probe->getNumberImages() > 0
                && $probe->getImageWidth() > 0
                && $probe->getImageHeight() > 0;
        } catch (\Throwable) {
            $decoded = false;
        } finally {
            $probe->clear();
            $probe->destroy();
        }

        if (! $decoded) {
            self::markTestSkipped("The runtime cannot decode the known-good {$format} fixture.");
        }
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }

    private function enableServiceCatalog(Organization $organization): void
    {
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
    }

    /** @return Testable<CreateSpecialistPage> */
    private function createSpecialistPage(User $admin): Testable
    {
        return Livewire::actingAs($admin)->test(new CreateSpecialistPage);
    }

    /** @return Testable<EditSpecialistPage> */
    private function editSpecialistPage(User $admin, Specialist $specialist): Testable
    {
        return Livewire::actingAs($admin)->test(new EditSpecialistPage, ['record' => $specialist->getKey()]);
    }
}
