<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_session_pains', function (Blueprint $table): void {
            $table->boolean('needs_confirmation')->default(false)->after('after_score');
            $table->string('state', 16)->default('active')->after('needs_confirmation');
            $table->index(['organization_id', 'client_id', 'state'], 'medical_session_pains_state_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE medical_session_pains ADD CONSTRAINT medical_session_pains_state_check CHECK (state IN ('active', 'resolved', 'returned'))");
        }
    }

    public function down(): void
    {
        throw new LogicException('Medical complaint lifecycle history is retained by forward-only migrations.');
    }
};
