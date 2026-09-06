<?php

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\EnsureReferralInviteTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Organization::query()->orderBy('id')->each(static function (Organization $organization): void {
            app(EnsureReferralInviteTemplate::class)->handle($organization);
        });
    }

    public function down(): void {}
};
