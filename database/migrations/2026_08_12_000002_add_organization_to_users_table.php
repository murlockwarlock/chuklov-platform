<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->boolean('is_admin')->default(false)->after('password');
            $table->index(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'email']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn('is_admin');
        });
    }
};
