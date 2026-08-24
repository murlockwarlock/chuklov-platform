<?php

use App\Modules\Conversations\Application\AdoptLegacyCompanionConversations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companion_turns', function (Blueprint $table): void {
            $table->string('image_reference_mode', 32)->default('none')->after('input_modality');
            $table->index(['organization_id', 'status', 'burst_expires_at'], 'companion_turns_assembly_index');
        });

        Schema::table('companion_deliveries', function (Blueprint $table): void {
            $table->timestampTz('uncertain_at')->nullable()->after('delivered_at');
        });

        Schema::table('conversation_bindings', function (Blueprint $table): void {
            $table->dropUnique('conversation_bindings_org_conversation_channel_unique');
            $table->unique(
                ['organization_id', 'conversation_id', 'channel', 'external_key'],
                'conversation_bindings_org_conversation_channel_external_unique',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE companion_turns DROP CONSTRAINT IF EXISTS companion_turns_status_check');
            DB::statement("ALTER TABLE companion_turns ADD CONSTRAINT companion_turns_status_check CHECK (status IN ('assembling', 'pending', 'processing', 'completed', 'failed', 'escalated', 'paused', 'cancelled'))");
            DB::statement('ALTER TABLE companion_turns ADD CONSTRAINT companion_turns_image_reference_mode_check CHECK (image_reference_mode IN (\'none\', \'recent_turn\'))');
            DB::statement('ALTER TABLE companion_deliveries DROP CONSTRAINT IF EXISTS companion_deliveries_status_check');
            DB::statement("ALTER TABLE companion_deliveries ADD CONSTRAINT companion_deliveries_status_check CHECK (status IN ('pending', 'processing', 'delivered', 'failed', 'uncertain'))");
        }

        app(AdoptLegacyCompanionConversations::class)->handle();
    }

    public function down(): void
    {
        $hasMultipleChannelBindings = DB::table('conversation_bindings')
            ->select(['organization_id', 'conversation_id', 'channel'])
            ->groupBy(['organization_id', 'conversation_id', 'channel'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($hasMultipleChannelBindings) {
            throw new RuntimeException('The Companion binding expansion cannot be rolled back without losing historical channel keys.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE companion_turns DROP CONSTRAINT IF EXISTS companion_turns_image_reference_mode_check');
            DB::statement('ALTER TABLE companion_turns DROP CONSTRAINT IF EXISTS companion_turns_status_check');
            DB::statement("ALTER TABLE companion_turns ADD CONSTRAINT companion_turns_status_check CHECK (status IN ('pending', 'processing', 'completed', 'failed', 'escalated', 'paused', 'cancelled'))");
            DB::statement('ALTER TABLE companion_deliveries DROP CONSTRAINT IF EXISTS companion_deliveries_status_check');
            DB::statement("ALTER TABLE companion_deliveries ADD CONSTRAINT companion_deliveries_status_check CHECK (status IN ('pending', 'processing', 'delivered', 'failed'))");
        }

        Schema::table('companion_deliveries', function (Blueprint $table): void {
            $table->dropColumn('uncertain_at');
        });
        Schema::table('companion_turns', function (Blueprint $table): void {
            $table->dropIndex('companion_turns_assembly_index');
            $table->dropColumn('image_reference_mode');
        });
        Schema::table('conversation_bindings', function (Blueprint $table): void {
            $table->dropUnique('conversation_bindings_org_conversation_channel_external_unique');
            $table->unique(
                ['organization_id', 'conversation_id', 'channel'],
                'conversation_bindings_org_conversation_channel_unique',
            );
        });
    }
};
