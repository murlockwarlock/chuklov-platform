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
            ->assertFormFieldDoesNotExist('payment_policy');

        $activeField = $page->getSchemaComponent('form.is_active');
        $paymentRequirementField = $page->getSchemaComponent('form.payment_requirement');
        self::assertInstanceOf(Toggle::class, $activeField);
        self::assertInstanceOf(Select::class, $paymentRequirementField);
        self::assertSame('Доступна для записи', $activeField->getLabel());
        self::assertSame('Когда клиент оплачивает', $paymentRequirementField->getLabel());
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
