<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('provider_name', 32)->nullable()->after('meeting_url');
            $table->string('provider_account_id', 255)->nullable()->after('provider_name');
            $table->string('provider_host_user_id', 255)->nullable()->after('provider_account_id');
            $table->string('provider_meeting_id', 128)->nullable()->after('provider_host_user_id');
            $table->string('provider_meeting_uuid', 191)->nullable()->after('provider_meeting_id');
            $table->string('provider_join_url', 2000)->nullable()->after('provider_meeting_uuid');
            $table->string('provider_sync_status', 40)->default('not_required')->after('provider_join_url');
            $table->string('provider_operation', 24)->nullable()->after('provider_sync_status');
            $table->unsignedInteger('provider_sync_version')->default(1)->after('provider_operation');
            $table->timestampTz('provider_synced_at')->nullable()->after('provider_sync_version');
            $table->string('provider_error_code', 120)->nullable()->after('provider_synced_at');
            $table->string('provider_correlation_key', 64)->nullable()->after('provider_error_code');
            $table->char('provider_lease_token', 64)->nullable()->after('provider_correlation_key');
            $table->timestampTz('provider_lease_expires_at')->nullable()->after('provider_lease_token');
            $table->unsignedBigInteger('provider_lease_event_id')->nullable()->after('provider_lease_expires_at');
            $table->char('provider_lease_processing_token', 64)->nullable()->after('provider_lease_event_id');
            $table->index(
                ['organization_id', 'provider_sync_status', 'starts_at'],
                'bookings_provider_sync_ix',
            );
            $table->index(
                ['organization_id', 'provider_lease_expires_at'],
                'bookings_provider_lease_ix',
            );
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_provider_state_ck CHECK ('
            ."(provider_name IS NULL OR provider_name = 'zoom') AND "
            ."provider_sync_status IN ('not_required', 'pending', 'ready', 'failed', 'cancellation_pending', 'reconciliation_required') AND "
            ."(provider_operation IS NULL OR provider_operation IN ('create', 'update', 'cancel', 'reconcile')) AND "
            .'((provider_account_id IS NULL AND provider_host_user_id IS NULL) OR '
            .'(provider_account_id IS NOT NULL AND provider_host_user_id IS NOT NULL)) AND '
            .'provider_sync_version > 0)'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_provider_state_ck');
        }

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex('bookings_provider_sync_ix');
            $table->dropIndex('bookings_provider_lease_ix');
            $table->dropColumn([
                'provider_name',
                'provider_account_id',
                'provider_host_user_id',
                'provider_meeting_id',
                'provider_meeting_uuid',
                'provider_join_url',
                'provider_sync_status',
                'provider_operation',
                'provider_sync_version',
                'provider_synced_at',
                'provider_error_code',
                'provider_correlation_key',
                'provider_lease_token',
                'provider_lease_expires_at',
                'provider_lease_event_id',
                'provider_lease_processing_token',
            ]);
        });
    }
};
