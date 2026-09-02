<?php

use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcast_campaigns', function (Blueprint $table): void {
            $table->string('audience_type', 24)->default('all')->after('send_mode');
            $table->jsonb('selected_client_ids')->default('[]')->after('segment_definition');
            $table->string('message_mode', 24)->default('saved_template')->after('selected_client_ids');
            $table->text('message_body')->nullable()->after('message_mode');
            $table->index(['organization_id', 'audience_type'], 'bc_campaign_org_audience_type_ix');
        });

        Schema::table('broadcast_audience_snapshots', function (Blueprint $table): void {
            $table->string('audience_type', 24)->default('all')->after('draft_version');
            $table->jsonb('selected_client_ids')->default('[]')->after('segment_definition');
        });

        BroadcastCampaign::query()
            ->where('audience_type', 'all')
            ->where('segment_definition', '!=', json_encode([]))
            ->update(['audience_type' => 'segment']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE broadcast_campaigns ADD CONSTRAINT bc_campaign_audience_type_ck CHECK (audience_type IN ('selected', 'all', 'segment'))");
            DB::statement("ALTER TABLE broadcast_campaigns ADD CONSTRAINT bc_campaign_message_mode_ck CHECK (message_mode IN ('compose', 'saved_template'))");
            DB::statement("ALTER TABLE broadcast_audience_snapshots ADD CONSTRAINT bc_snapshot_audience_type_ck CHECK (audience_type IN ('selected', 'all', 'segment'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE broadcast_audience_snapshots DROP CONSTRAINT IF EXISTS bc_snapshot_audience_type_ck');
            DB::statement('ALTER TABLE broadcast_campaigns DROP CONSTRAINT IF EXISTS bc_campaign_message_mode_ck');
            DB::statement('ALTER TABLE broadcast_campaigns DROP CONSTRAINT IF EXISTS bc_campaign_audience_type_ck');
        }

        Schema::table('broadcast_audience_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['audience_type', 'selected_client_ids']);
        });

        Schema::table('broadcast_campaigns', function (Blueprint $table): void {
            $table->dropIndex('bc_campaign_org_audience_type_ix');
            $table->dropColumn(['audience_type', 'selected_client_ids', 'message_mode', 'message_body']);
        });
    }
};
