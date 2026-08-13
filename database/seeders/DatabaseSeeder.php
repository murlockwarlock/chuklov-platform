<?php

namespace Database\Seeders;

use App\Modules\Organizations\Domain\Models\Organization;
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
        Organization::query()->firstOrCreate(
            ['slug' => 'chuklov'],
            ['name' => 'Chuklov', 'timezone' => 'Asia/Bangkok'],
        );

        $this->call(ScenarioNotificationSeeder::class);
    }
}
