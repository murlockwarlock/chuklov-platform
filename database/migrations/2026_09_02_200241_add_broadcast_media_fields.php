<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcast_campaigns', function (Blueprint $table): void {
            $table->string('delivery_mode', 32)->default('text')->after('message_mode');
            $table->string('caption_position', 12)->default('below')->after('delivery_mode');
            $table->jsonb('media')->nullable()->after('message_body');
        });

        Schema::table('broadcast_audience_snapshots', function (Blueprint $table): void {
            $table->string('delivery_mode', 32)->default('text')->after('channel_priority');
            $table->string('caption_position', 12)->default('below')->after('delivery_mode');
            $table->jsonb('media')->nullable()->after('caption_position');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE broadcast_campaigns ADD CONSTRAINT bc_campaign_delivery_mode_ck CHECK (delivery_mode IN ('text','image','image_then_text','text_then_image','image_caption'))");
            DB::statement("ALTER TABLE broadcast_campaigns ADD CONSTRAINT bc_campaign_caption_position_ck CHECK (caption_position IN ('above','below'))");
            DB::statement("ALTER TABLE broadcast_audience_snapshots ADD CONSTRAINT bc_snapshot_delivery_mode_ck CHECK (delivery_mode IN ('text','image','image_then_text','text_then_image','image_caption'))");
            DB::statement("ALTER TABLE broadcast_audience_snapshots ADD CONSTRAINT bc_snapshot_caption_position_ck CHECK (caption_position IN ('above','below'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE broadcast_audience_snapshots DROP CONSTRAINT IF EXISTS bc_snapshot_caption_position_ck');
            DB::statement('ALTER TABLE broadcast_audience_snapshots DROP CONSTRAINT IF EXISTS bc_snapshot_delivery_mode_ck');
            DB::statement('ALTER TABLE broadcast_campaigns DROP CONSTRAINT IF EXISTS bc_campaign_caption_position_ck');
            DB::statement('ALTER TABLE broadcast_campaigns DROP CONSTRAINT IF EXISTS bc_campaign_delivery_mode_ck');
        }

        Schema::table('broadcast_audience_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['delivery_mode', 'caption_position', 'media']);
        });

        Schema::table('broadcast_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['delivery_mode', 'caption_position', 'media']);
        });
    }
};
