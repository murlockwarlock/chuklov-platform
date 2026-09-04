<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('organizations')
            ->orderBy('id')
            ->pluck('id')
            ->each(function (mixed $organizationId): void {
                $organizationId = (int) $organizationId;
                $timestamp = now();

                DB::table('organization_settings')->insertOrIgnore([
                    'organization_id' => $organizationId,
                    'setting_key' => 'home_visit_occupied_buffer_minutes',
                    'value_type' => 'integer',
                    'string_value' => null,
                    'integer_value' => 150,
                    'boolean_value' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $legacyAddress = DB::table('organization_settings')
                    ->where('organization_id', $organizationId)
                    ->where('setting_key', 'office_location')
                    ->value('string_value');
                $legacyAddress = is_string($legacyAddress) ? trim($legacyAddress) : '';

                if ($legacyAddress === ''
                    || DB::table('working_locations')
                        ->where('organization_id', $organizationId)
                        ->where('is_default_office', true)
                        ->where('is_active', true)
                        ->exists()) {
                    return;
                }

                $existing = DB::table('working_locations')
                    ->where('organization_id', $organizationId)
                    ->where('address', $legacyAddress)
                    ->orderBy('id')
                    ->first(['id']);
                if ($existing !== null) {
                    DB::table('working_locations')
                        ->where('organization_id', $organizationId)
                        ->update(['is_default_office' => false, 'updated_at' => $timestamp]);
                    DB::table('working_locations')
                        ->where('organization_id', $organizationId)
                        ->where('id', $existing->id)
                        ->update([
                            'is_default_office' => true,
                            'is_active' => true,
                            'updated_at' => $timestamp,
                        ]);

                    return;
                }

                DB::table('working_locations')
                    ->where('organization_id', $organizationId)
                    ->update(['is_default_office' => false, 'updated_at' => $timestamp]);

                $organizationTimezone = DB::table('organization_settings')
                    ->where('organization_id', $organizationId)
                    ->where('setting_key', 'default_timezone')
                    ->value('string_value');
                $organizationTimezone = is_string($organizationTimezone) && trim($organizationTimezone) !== ''
                    ? trim($organizationTimezone)
                    : (string) DB::table('organizations')->where('id', $organizationId)->value('timezone');

                DB::table('working_locations')->insert([
                    'organization_id' => $organizationId,
                    'name' => 'Основной кабинет',
                    'address' => $legacyAddress,
                    'timezone' => $organizationTimezone,
                    'latitude' => null,
                    'longitude' => null,
                    'map_url' => null,
                    'is_active' => true,
                    'is_default_office' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            });
    }

    public function down(): void {}
};
