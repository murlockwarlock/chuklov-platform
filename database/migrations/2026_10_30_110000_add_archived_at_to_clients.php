<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'archived_at')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->timestampTz('archived_at')->nullable();
                $table->index(['organization_id', 'archived_at'], 'clients_org_archived_index');
            });
        }
    }

    public function down(): void
    {
        throw new LogicException('Client archive state is retained by forward-only migrations.');
    }
};
