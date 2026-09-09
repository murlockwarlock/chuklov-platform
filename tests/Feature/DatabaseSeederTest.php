<?php

namespace Tests\Feature;

use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_bootstraps_tracker_settings(): void
    {
        $this->seed(DatabaseSeeder::class);

        self::assertSame(false, OrganizationSetting::query()
            ->where('setting_key', OrganizationSettingKey::TrackerFreeMode->value)
            ->value('boolean_value'));
        self::assertSame(true, OrganizationSetting::query()
            ->where('setting_key', OrganizationSettingKey::TrackerEnabled->value)
            ->value('boolean_value'));
    }
}
