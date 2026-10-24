<?php

namespace Tests\Feature;

use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Services\Domain\Enums\CatalogItemType;
use App\Modules\Services\Domain\Enums\ServicePaymentRequirement;
use App\Modules\Services\Domain\Models\Service;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Tests\TestCase;

final class ServiceCatalogFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ru');
    }

    protected function tearDown(): void
    {
        app()->setLocale('ru');

        parent::tearDown();
    }

    public function test_service_form_exposes_booking_fields_and_hides_legacy_payment_policy(): void
    {
        [, $admin] = $this->fixture();

        Testable::actingAs($admin);
        $component = Testable::create(CreateService::class);
        $page = $component->instance();
        self::assertInstanceOf(CreateService::class, $page);
        $catalogType = $page->getSchemaComponent('form.catalog_type');

        self::assertInstanceOf(Select::class, $catalogType);
        self::assertTrue($catalogType->isLive());
        self::assertSame('Физический товар', $catalogType->getOptions()[CatalogItemType::PhysicalProduct->value] ?? null);

        $component
            ->assertFormFieldVisible('duration_minutes')
            ->assertFormFieldVisible('buffer_minutes')
            ->assertFormFieldVisible('formats')
            ->assertFormFieldVisible('payment_requirement')
            ->assertFormFieldVisible('requires_medical_profile')
            ->assertFormFieldDoesNotExist('payment_policy');

        $activeField = $page->getSchemaComponent('form.is_active');
        $paymentRequirementField = $page->getSchemaComponent('form.payment_requirement');
        $requiresMedicalProfileField = $page->getSchemaComponent('form.requires_medical_profile');
        self::assertInstanceOf(Toggle::class, $activeField);
        self::assertInstanceOf(Select::class, $paymentRequirementField);
        self::assertInstanceOf(Toggle::class, $requiresMedicalProfileField);
        self::assertSame('Доступна для записи', $activeField->getLabel());
        self::assertSame('Когда клиент оплачивает', $paymentRequirementField->getLabel());
        self::assertSame('Требуется медицинская анкета', $requiresMedicalProfileField->getLabel());
        self::assertSame('Перерыв — кабинет, минут', $page->getSchemaComponent('form.buffer_office_minutes')->getLabel());
        self::assertSame('Перерыв — выезд, минут', $page->getSchemaComponent('form.buffer_home_minutes')->getLabel());
        self::assertSame('Перерыв — онлайн, минут', $page->getSchemaComponent('form.buffer_online_minutes')->getLabel());
        $component->assertSet('data.requires_medical_profile', true);
        self::assertSame('Перерыв после приёма, минут', $page->getSchemaComponent('form.buffer_minutes')->getLabel());
        self::assertStringContainsString(
            'Это время блокируется после завершения услуги перед следующей записью.',
            $component->html(),
        );
    }

    public function test_service_medical_profile_requirement_is_created_and_persisted_through_edit_and_reopen(): void
    {
        [$organization, $admin] = $this->fixture();
        Testable::actingAs($admin);

        $defaultServiceForm = Testable::create(CreateService::class);
        $defaultServiceForm
            ->assertSet('data.requires_medical_profile', true)
            ->fillForm([
                'name' => 'Первичная консультация',
                'summary' => 'Синтетическая услуга для проверки флага анкеты.',
                'catalog_type' => CatalogItemType::Service->value,
                'is_active' => true,
                'duration_minutes' => 45,
                'buffer_office_minutes' => 7,
                'buffer_home_minutes' => 13,
                'buffer_online_minutes' => 19,
                'formats' => ['office', 'home', 'online'],
                'payment_requirement' => ServicePaymentRequirement::Postpay->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $defaultService = Service::query()
            ->where('organization_id', $organization->getKey())
            ->where('name', 'Первичная консультация')
            ->sole();
        self::assertTrue((bool) DB::table('services')->where('id', $defaultService->getKey())->value('requires_medical_profile'));
        self::assertSame([7, 13, 19], array_map(
            static fn (string $field): int => (int) DB::table('services')->where('id', $defaultService->getKey())->value($field),
            ['buffer_office_minutes', 'buffer_home_minutes', 'buffer_online_minutes'],
        ));

        Testable::create(EditService::class, ['record' => $defaultService->getRouteKey()])
            ->assertSet('data.requires_medical_profile', true)
            ->assertSet('data.buffer_office_minutes', 7)
            ->assertSet('data.buffer_home_minutes', 13)
            ->assertSet('data.buffer_online_minutes', 19)
            ->assertFormFieldVisible('buffer_office_minutes')
            ->assertFormFieldVisible('buffer_home_minutes')
            ->assertFormFieldVisible('buffer_online_minutes')
            ->assertFormFieldVisible('requires_medical_profile')
            ->set('data.requires_medical_profile', false)
            ->call('save')
            ->assertHasNoFormErrors();

        self::assertFalse((bool) DB::table('services')->where('id', $defaultService->getKey())->value('requires_medical_profile'));
        self::assertSame([7, 13, 19], array_map(
            static fn (string $field): int => (int) DB::table('services')->where('id', $defaultService->getKey())->value($field),
            ['buffer_office_minutes', 'buffer_home_minutes', 'buffer_online_minutes'],
        ));
        Testable::create(EditService::class, ['record' => $defaultService->getRouteKey()])
            ->assertSet('data.requires_medical_profile', false)
            ->assertSet('data.buffer_office_minutes', 7)
            ->assertSet('data.buffer_home_minutes', 13)
            ->assertSet('data.buffer_online_minutes', 19)
            ->assertFormFieldVisible('requires_medical_profile');

        $disabledServiceForm = Testable::create(CreateService::class);
        $disabledServiceForm
            ->fillForm([
                'name' => 'Консультация без анкеты',
                'summary' => 'Синтетическая услуга без медицинской анкеты.',
                'catalog_type' => CatalogItemType::Service->value,
                'is_active' => true,
                'requires_medical_profile' => false,
                'duration_minutes' => 45,
                'formats' => ['office'],
                'payment_requirement' => ServicePaymentRequirement::Postpay->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $disabledService = Service::query()
            ->where('organization_id', $organization->getKey())
            ->where('name', 'Консультация без анкеты')
            ->sole();
        self::assertFalse((bool) DB::table('services')->where('id', $disabledService->getKey())->value('requires_medical_profile'));
        Testable::create(EditService::class, ['record' => $disabledService->getRouteKey()])
            ->assertSet('data.requires_medical_profile', false)
            ->assertFormFieldVisible('requires_medical_profile')
            ->set('data.requires_medical_profile', true)
            ->call('save')
            ->assertHasNoFormErrors();

        self::assertTrue((bool) DB::table('services')->where('id', $disabledService->getKey())->value('requires_medical_profile'));
        Testable::create(EditService::class, ['record' => $disabledService->getRouteKey()])
            ->assertSet('data.requires_medical_profile', true);
    }

    public function test_service_form_labels_follow_the_english_crm_locale(): void
    {
        [, $admin] = $this->fixture();
        app()->setLocale('en');

        Testable::actingAs($admin);
        $page = Testable::create(CreateService::class)->instance();

        self::assertSame('Offer type', $page->getSchemaComponent('form.catalog_type')->getLabel());
        self::assertSame('Available for booking', $page->getSchemaComponent('form.is_active')->getLabel());
        self::assertSame('When the customer pays', $page->getSchemaComponent('form.payment_requirement')->getLabel());
    }

    public function test_online_product_form_hides_booking_configuration_and_uses_product_copy(): void
    {
        [, $admin] = $this->fixture();

        Testable::actingAs($admin);
        $component = Testable::create(CreateService::class);
        $component
            ->set('data.catalog_type', CatalogItemType::OnlineProduct->value)
            ->set('data.lava_enabled', true);

        $component
            ->assertFormFieldHidden('duration_minutes')
            ->assertFormFieldHidden('buffer_minutes')
            ->assertFormFieldHidden('formats')
            ->assertFormFieldHidden('payment_requirement')
            ->assertFormFieldVisible('price')
            ->assertFormFieldVisible('price_currency')
            ->assertFormFieldVisible('lava_enabled')
            ->assertFormFieldVisible('service_image')
            ->assertFormFieldVisible('external_image_url')
            ->assertFormFieldVisible('name_ru')
            ->assertFormFieldVisible('name_en')
            ->assertFormFieldVisible('description_ru')
            ->assertFormFieldVisible('description_en');

        $page = $component->instance();
        self::assertInstanceOf(CreateService::class, $page);
        $activeField = $page->getSchemaComponent('form.is_active');
        self::assertInstanceOf(Toggle::class, $activeField);
        self::assertSame('Показывать клиентам', $activeField->getLabel());
        self::assertStringContainsString(
            'ID предложения из кабинета Lava. Он связывает этот товар с предложением, созданным в Lava.',
            $component->html(),
        );
    }

    public function test_gift_certificate_form_renders_nominal_helper_text(): void
    {
        [, $admin] = $this->fixture();

        Testable::actingAs($admin);
        Testable::create(CreateService::class)
            ->set('data.catalog_type', CatalogItemType::GiftCertificate->value)
            ->assertFormFieldVisible('price')
            ->assertSee('Цена сертификата одновременно является его номиналом.');
    }

    public function test_physical_product_form_hides_booking_configuration_and_existing_rows_remain_editable(): void
    {
        [$organization, $admin] = $this->fixture();
        $physicalProduct = Service::factory()->forOrganization($organization)->create([
            'catalog_type' => CatalogItemType::PhysicalProduct->value,
        ]);
        self::assertInstanceOf(Service::class, $physicalProduct);

        Testable::actingAs($admin);
        $create = Testable::create(CreateService::class);
        $createPage = $create->instance();
        self::assertInstanceOf(CreateService::class, $createPage);
        $createCatalogType = $createPage->getSchemaComponent('form.catalog_type');
        self::assertInstanceOf(Select::class, $createCatalogType);
        self::assertSame('Физический товар', $createCatalogType->getOptions()[CatalogItemType::PhysicalProduct->value] ?? null);

        $create
            ->set('data.catalog_type', CatalogItemType::PhysicalProduct->value)
            ->assertFormFieldHidden('duration_minutes')
            ->assertFormFieldHidden('buffer_minutes')
            ->assertFormFieldHidden('formats')
            ->assertFormFieldHidden('payment_requirement')
            ->assertFormFieldVisible('price')
            ->assertFormFieldVisible('price_currency');

        $edit = Testable::create(EditService::class, ['record' => $physicalProduct->getRouteKey()]);
        $edit->assertSuccessful();
        $editPage = $edit->instance();
        self::assertInstanceOf(EditService::class, $editPage);
        $editCatalogType = $editPage->getSchemaComponent('form.catalog_type');
        self::assertInstanceOf(Select::class, $editCatalogType);
        $options = $editCatalogType->getOptions();

        self::assertSame('Физический товар', $options[CatalogItemType::PhysicalProduct->value] ?? null);
        $edit
            ->assertFormFieldHidden('duration_minutes')
            ->assertFormFieldHidden('payment_requirement');

        self::assertSame('Показывать клиентам', $editPage->getSchemaComponent('form.is_active')->getLabel());
    }

    public function test_online_product_can_be_created_without_booking_configuration_or_lava(): void
    {
        [$organization, $admin] = $this->fixture();

        Testable::actingAs($admin);
        Testable::create(CreateService::class)
            ->fillForm([
                'name' => 'Онлайн-курс',
                'summary' => 'Доступ к материалам курса.',
                'catalog_type' => CatalogItemType::OnlineProduct->value,
                'category' => 'Курсы',
                'is_active' => true,
                'price' => '15000.50',
                'price_currency' => 'RUB',
                'name_ru' => 'Онлайн-курс',
                'name_en' => 'Online course',
                'description_ru' => 'Описание курса.',
                'description_en' => 'Course description.',
                'external_image_url' => 'https://cdn.example.test/course.jpg',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Service::query()->where('organization_id', $organization->getKey())->sole();

        self::assertSame(CatalogItemType::OnlineProduct, $product->catalog_type);
        self::assertSame(1_500_050, $product->price_minor);
        self::assertSame('RUB', $product->price_currency);
        self::assertNull($product->duration_minutes);
        self::assertSame(0, $product->buffer_minutes);
        self::assertSame([], $product->formats);
        self::assertSame('Онлайн-курс', $product->name_ru);
        self::assertSame('Online course', $product->name_en);
        self::assertSame('https://cdn.example.test/course.jpg', $product->external_image_url);
        self::assertNull($product->payment_policy);
        self::assertSame(ServicePaymentRequirement::Postpay, $product->payment_requirement);
    }

    public function test_physical_product_can_be_created_without_booking_configuration(): void
    {
        [$organization, $admin] = $this->fixture();

        Testable::actingAs($admin);
        Testable::create(CreateService::class)
            ->fillForm([
                'name' => 'Набор материалов',
                'summary' => 'Физический набор для курса.',
                'catalog_type' => CatalogItemType::PhysicalProduct->value,
                'is_active' => true,
                'price' => '100.00',
                'price_currency' => 'RUB',
                'name_ru' => 'Набор материалов',
                'name_en' => 'Materials set',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Service::query()->where('organization_id', $organization->getKey())->sole();

        self::assertSame(CatalogItemType::PhysicalProduct, $product->catalog_type);
        self::assertSame(10000, $product->price_minor);
        self::assertSame('RUB', $product->price_currency);
        self::assertNull($product->duration_minutes);
        self::assertSame([], $product->formats);
    }

    public function test_switching_a_service_to_an_online_product_preserves_legacy_and_booking_values(): void
    {
        [$organization, $admin] = $this->fixture();
        $service = Service::factory()->forOrganization($organization)->create([
            'catalog_type' => CatalogItemType::Service->value,
            'duration_minutes' => 90,
            'buffer_minutes' => 20,
            'formats' => ['office', 'online'],
            'payment_policy' => 'manual',
            'payment_requirement' => ServicePaymentRequirement::PrepayFull->value,
        ]);
        self::assertInstanceOf(Service::class, $service);

        Testable::create(EditService::class, ['record' => $service->getRouteKey()])
            ->set('data.catalog_type', CatalogItemType::OnlineProduct->value)
            ->call('save')
            ->assertHasNoFormErrors();

        $updated = Service::query()->find($service->getKey());

        self::assertInstanceOf(Service::class, $updated);
        self::assertSame(CatalogItemType::OnlineProduct, $updated->catalog_type);
        self::assertSame(90, $updated->duration_minutes);
        self::assertSame(20, $updated->buffer_minutes);
        self::assertSame(['office', 'online'], $updated->formats);
        self::assertSame('manual', $updated->payment_policy);
        self::assertSame(ServicePaymentRequirement::PrepayFull, $updated->payment_requirement);
    }

    /** @return array{Organization, User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return [$organization, $admin];
    }
}
