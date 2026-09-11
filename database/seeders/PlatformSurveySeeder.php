<?php

namespace Database\Seeders;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Surveys\Application\InstallPlatformSurveyCatalog;
use Illuminate\Database\Seeder;

class PlatformSurveySeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->where('slug', 'chuklov')->first();
        if ($organization === null) {
            return;
        }

        app(InstallPlatformSurveyCatalog::class)->handle($organization);
    }
}
