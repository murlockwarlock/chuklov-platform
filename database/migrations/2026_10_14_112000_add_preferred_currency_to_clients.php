<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->char('preferred_currency', 3)->nullable();
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_preferred_currency_check CHECK (preferred_currency IS NULL OR preferred_currency ~ '^[A-Z]{3}$')");
        }
    }

    public function down(): void
    {
        throw new LogicException('Client currency preferences are retained by forward-only migrations.');
    }
};
