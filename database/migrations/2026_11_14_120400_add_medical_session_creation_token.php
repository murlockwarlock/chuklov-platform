<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_sessions', function (Blueprint $table): void {
            $table->uuid('creation_token')->nullable()->after('booking_id');
            $table->unique(
                ['organization_id', 'creation_token'],
                'medical_sessions_organization_creation_token_unique',
            );
        });
    }

    public function down(): void
    {
        throw new LogicException('Medical session creation tokens are retained by forward-only migrations.');
    }
};
