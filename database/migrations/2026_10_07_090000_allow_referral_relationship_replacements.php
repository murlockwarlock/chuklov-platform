<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_relationships', function (Blueprint $table): void {
            $table->timestampTz('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_relationship_id')->nullable();
        });

        Schema::table('referral_relationships', function (Blueprint $table): void {
            $table->foreign(
                ['organization_id', 'superseded_by_relationship_id', 'referred_client_id'],
                'ref_rel_superseded_by_fk',
            )
                ->references(['organization_id', 'id', 'referred_client_id'])
                ->on('referral_relationships')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE referral_relationships DROP CONSTRAINT IF EXISTS ref_rel_org_referred_unique');
        } else {
            DB::statement('DROP INDEX IF EXISTS ref_rel_org_referred_unique');
        }
        DB::statement(
            'CREATE UNIQUE INDEX ref_rel_org_referred_active_unique ON referral_relationships (organization_id, referred_client_id) WHERE superseded_at IS NULL',
        );
    }

    public function down(): void
    {
        Schema::table('referral_relationships', function (Blueprint $table): void {
            $table->dropForeign('ref_rel_superseded_by_fk');
        });

        DB::statement('DROP INDEX IF EXISTS ref_rel_org_referred_active_unique');

        Schema::table('referral_relationships', function (Blueprint $table): void {
            $table->dropColumn(['superseded_at', 'superseded_by_relationship_id']);
            $table->unique(['organization_id', 'referred_client_id'], 'ref_rel_org_referred_unique');
        });
    }
};
