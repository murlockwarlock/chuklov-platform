<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_session_attachments', function (Blueprint $table): void {
            $table->string('plane', 32)->nullable()->after('gallery');
            $table->index(['organization_id', 'client_id', 'plane'], 'medical_session_attachment_plane_index');
        });
    }

    public function down(): void
    {
        throw new LogicException('The medical Stage 1C photo plane migration is forward-only.');
    }
};
