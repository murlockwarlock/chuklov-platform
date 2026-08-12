<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE services ALTER COLUMN duration_minutes TYPE integer '
            .'USING duration_minutes::integer'
        );
        DB::statement(
            'ALTER TABLE services ALTER COLUMN buffer_minutes TYPE integer '
            .'USING buffer_minutes::integer'
        );
        DB::statement(
            'ALTER TABLE content_sections ALTER COLUMN sort_order TYPE bigint '
            .'USING sort_order::bigint'
        );

        DB::statement(
            'ALTER TABLE services ADD CONSTRAINT services_duration_minutes_range '
            .'CHECK (duration_minutes IS NULL OR (duration_minutes > 0 AND duration_minutes <= 65535))'
        );
        DB::statement(
            'ALTER TABLE services ADD CONSTRAINT services_buffer_minutes_range '
            .'CHECK (buffer_minutes >= 0 AND buffer_minutes <= 65535)'
        );
        DB::statement(
            'ALTER TABLE services ADD CONSTRAINT services_price_minor_non_negative '
            .'CHECK (price_minor IS NULL OR price_minor >= 0)'
        );
        DB::statement(
            'ALTER TABLE content_sections ADD CONSTRAINT content_sections_sort_order_non_negative '
            .'CHECK (sort_order >= 0)'
        );
    }

    public function down(): void {}
};
