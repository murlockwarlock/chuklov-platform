<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Enums\OrganizationSettingType;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationSetting>
 */
class OrganizationSettingFactory extends Factory
{
    protected $model = OrganizationSetting::class;

    public function definition(): array
    {
        return [
            'setting_key' => 'default_language',
            'value_type' => OrganizationSettingType::String->value,
            'string_value' => 'en',
            'integer_value' => null,
            'boolean_value' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (OrganizationSetting $setting): OrganizationSetting => $setting->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }
}
