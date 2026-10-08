<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('author_user_id');
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['organization_id', 'client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'author_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->restrictOnDelete();
            $table->index(['organization_id', 'client_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS client_notes_organization_id_client_id_created_at_id_index');
        Schema::dropIfExists('client_notes');
    }
};
