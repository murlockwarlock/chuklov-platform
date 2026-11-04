<?php

use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $configuredLtvOrganizations = DB::table('organization_settings')
            ->where('setting_key', OrganizationSettingKey::AnalyticsLtvPercentFormulaReference->value)
            ->whereNotNull('string_value')
            ->whereRaw("TRIM(string_value) <> ''")
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $configuredTherapyOrganizations = DB::table('organization_settings')
            ->where('setting_key', OrganizationSettingKey::AnalyticsTherapyEffectivenessFormulaReference->value)
            ->whereNotNull('string_value')
            ->whereRaw("TRIM(string_value) <> ''")
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        DB::table('analytics_metric_configurations')
            ->where('stable_key', 'ltv_change_percent')
            ->when($configuredLtvOrganizations !== [], fn ($query) => $query->whereNotIn('organization_id', $configuredLtvOrganizations))
            ->update([
                'active' => false,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
        DB::table('analytics_metric_configurations')
            ->where('stable_key', 'pain_reduction_percent')
            ->when($configuredTherapyOrganizations !== [], fn ($query) => $query->whereNotIn('organization_id', $configuredTherapyOrganizations))
            ->update([
                'active' => false,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void {}
};
