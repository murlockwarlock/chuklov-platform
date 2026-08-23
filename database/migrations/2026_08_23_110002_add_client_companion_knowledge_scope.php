<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table): void {
            $table->boolean('client_companion_enabled')->default(false)->after('status');
            $table->index(['organization_id', 'status', 'client_companion_enabled'], 'knowledge_sources_companion_scope_index');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table): void {
            $table->dropIndex('knowledge_sources_companion_scope_index');
            $table->dropColumn('client_companion_enabled');
        });
    }
};
