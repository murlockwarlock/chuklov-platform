<?php

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\EnsureOperationalNotificationDefaults;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Organization::query()->orderBy('id')->each(
            static fn (Organization $organization): mixed => app(EnsureOperationalNotificationDefaults::class)->handle($organization),
        );
    }

    public function down(): void {}
};
