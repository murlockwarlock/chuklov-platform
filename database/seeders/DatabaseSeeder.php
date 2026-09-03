<?php

namespace Database\Seeders;

use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'chuklov'],
            ['name' => 'Chuklov', 'timezone' => 'Asia/Almaty'],
        );

        OrganizationSetting::query()->firstOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'setting_key' => OrganizationSettingKey::HomeVisitOccupiedBufferMinutes->value,
            ],
            [
                'value_type' => OrganizationSettingKey::HomeVisitOccupiedBufferMinutes->type(),
                'integer_value' => 150,
            ],
        );

        $this->call([
            LegalDocumentSeeder::class,
            ScenarioNotificationSeeder::class,
        ]);
    }
}
