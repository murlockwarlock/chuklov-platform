<?php

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\EnsureOperationalNotificationDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Organization::query()->orderBy('id')->each(
            static fn (Organization $organization): mixed => app(EnsureOperationalNotificationDefaults::class)->handle($organization),
        );
    }

    public function down(): void
    {
        DB::table('scenario_rules')
            ->whereIn('rule_key', ['companion-handoff-database', 'companion-handoff-telegram'])
            ->delete();
        $templateIds = DB::table('notification_templates')
            ->where('template_key', 'companion-handoff')
            ->pluck('id');
        DB::table('notification_template_versions')->whereIn('template_id', $templateIds)->delete();
        DB::table('notification_templates')->whereIn('id', $templateIds)->delete();
    }
};
