<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_template_versions', function (Blueprint $table): void {
            $table->string('delivery_mode', 32)->default('text')->after('status');
            $table->string('caption_position', 12)->default('below')->after('delivery_mode');
            $table->jsonb('media')->nullable()->after('body');
        });

        DB::statement("ALTER TABLE notification_template_versions ADD CONSTRAINT notification_template_versions_delivery_mode_check CHECK (delivery_mode IN ('text','image','image_then_text','text_then_image','image_caption'))");
        DB::statement("ALTER TABLE notification_template_versions ADD CONSTRAINT notification_template_versions_caption_position_check CHECK (caption_position IN ('above','below'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notification_template_versions DROP CONSTRAINT IF EXISTS notification_template_versions_caption_position_check');
        DB::statement('ALTER TABLE notification_template_versions DROP CONSTRAINT IF EXISTS notification_template_versions_delivery_mode_check');

        Schema::table('notification_template_versions', function (Blueprint $table): void {
            $table->dropColumn(['delivery_mode', 'caption_position', 'media']);
        });
    }
};
