<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_partner_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'ref_partner_profile_org_fk')
                ->restrictOnDelete();
            $table->foreignId('client_id');
            $table->string('status', 24)->default('active');
            $table->timestampTz('activated_at');
            $table->string('activation_source', 32);
            $table->foreignId('activated_by_user_id')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->foreignId('deactivated_by_user_id')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'ref_partner_profile_org_id_unique');
            $table->unique(['organization_id', 'client_id'], 'ref_partner_profile_org_client_unique');
            $table->unique(['organization_id', 'id', 'client_id'], 'ref_partner_profile_org_id_client_unique');
            $table->index(['organization_id', 'status', 'activated_at'], 'ref_partner_profile_org_status_index');
            $table->foreign(['organization_id', 'client_id'], 'ref_partner_profile_org_client_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            foreach (['activated_by_user_id', 'deactivated_by_user_id'] as $column) {
                $table->foreign(['organization_id', $column], 'ref_partner_profile_org_'.str_replace('_by_user_id', '_by_user_fk', $column))
                    ->references(['organization_id', 'user_id'])
                    ->on('organization_memberships')
                    ->nullOnDelete();
            }
        });

        Schema::create('referral_campaign_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'ref_campaign_link_org_fk')
                ->restrictOnDelete();
            $table->foreignId('partner_profile_id');
            $table->foreignId('partner_client_id');
            $table->foreignId('referral_identity_id');
            $table->string('public_token', 128);
            $table->string('name', 180);
            $table->string('channel', 32);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestampTz('disabled_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->foreignId('disabled_by_user_id')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'ref_campaign_link_org_id_unique');
            $table->unique(['organization_id', 'public_token'], 'ref_campaign_link_org_token_unique');
            $table->unique(['organization_id', 'id', 'partner_client_id'], 'ref_campaign_link_org_id_client_unique');
            $table->index(['organization_id', 'partner_profile_id', 'is_active', 'created_at'], 'ref_campaign_link_org_profile_active_index');
            $table->index(['organization_id', 'referral_identity_id'], 'ref_campaign_link_org_identity_index');
            $table->foreign(['organization_id', 'partner_profile_id', 'partner_client_id'], 'ref_campaign_link_org_profile_fk')
                ->references(['organization_id', 'id', 'client_id'])
                ->on('referral_partner_profiles')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'partner_client_id'], 'ref_campaign_link_org_client_fk')
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'referral_identity_id', 'partner_client_id'], 'ref_campaign_link_org_identity_client_fk')
                ->references(['organization_id', 'id', 'client_id'])
                ->on('client_referral_identities')
                ->restrictOnDelete();
            foreach (['created_by_user_id', 'disabled_by_user_id'] as $column) {
                $table->foreign(['organization_id', $column], 'ref_campaign_link_org_'.str_replace('_by_user_id', '_by_user_fk', $column))
                    ->references(['organization_id', 'user_id'])
                    ->on('organization_memberships')
                    ->nullOnDelete();
            }
        });

        Schema::create('referral_link_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations', 'id', 'ref_link_visit_org_fk')
                ->restrictOnDelete();
            $table->foreignId('campaign_link_id');
            $table->char('session_hash', 64);
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'ref_link_visit_org_id_unique');
            $table->index(['organization_id', 'campaign_link_id', 'occurred_at'], 'ref_link_visit_org_link_occurred_index');
            $table->index(['organization_id', 'session_hash', 'occurred_at'], 'ref_link_visit_org_session_occurred_index');
            $table->foreign(['organization_id', 'campaign_link_id'], 'ref_link_visit_org_link_fk')
                ->references(['organization_id', 'id'])
                ->on('referral_campaign_links')
                ->restrictOnDelete();
        });

        Schema::table('referral_relationships', function (Blueprint $table): void {
            $table->foreignId('referral_campaign_link_id')->nullable()->after('establishment_method');
            $table->index(
                ['organization_id', 'referral_campaign_link_id', 'registered_at'],
                'ref_rel_org_campaign_registered_index',
            );
            $table->foreign(
                ['organization_id', 'referral_campaign_link_id', 'referrer_client_id'],
                'ref_rel_org_campaign_referrer_fk',
            )
                ->references(['organization_id', 'id', 'partner_client_id'])
                ->on('referral_campaign_links')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE referral_partner_profiles ADD CONSTRAINT ref_partner_profile_status_check CHECK (status IN ('active', 'inactive') AND activation_source IN ('portal', 'crm'))");
            DB::statement("ALTER TABLE referral_campaign_links ADD CONSTRAINT ref_campaign_link_shape_check CHECK (char_length(trim(name)) BETWEEN 2 AND 180 AND channel IN ('telegram', 'instagram', 'youtube', 'whatsapp', 'website', 'other') AND (is_active OR disabled_at IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE referral_campaign_links DROP CONSTRAINT IF EXISTS ref_campaign_link_shape_check');
            DB::statement('ALTER TABLE referral_partner_profiles DROP CONSTRAINT IF EXISTS ref_partner_profile_status_check');
        }

        Schema::table('referral_relationships', function (Blueprint $table): void {
            $table->dropForeign('ref_rel_org_campaign_referrer_fk');
            $table->dropIndex('ref_rel_org_campaign_registered_index');
            $table->dropColumn('referral_campaign_link_id');
        });
        Schema::dropIfExists('referral_link_visits');
        Schema::dropIfExists('referral_campaign_links');
        Schema::dropIfExists('referral_partner_profiles');
    }
};
