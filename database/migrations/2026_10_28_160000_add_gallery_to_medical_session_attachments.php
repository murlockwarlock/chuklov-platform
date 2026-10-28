<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_session_attachments', function (Blueprint $table): void {
            $table->string('gallery', 32)->default('general')->after('medical_attachment_id');
            $table->index(
                ['organization_id', 'medical_session_id', 'gallery', 'id'],
                'medical_session_attachments_gallery_index',
            );
        });
    }

    public function down(): void
    {
        throw new LogicException('The medical session attachment gallery migration is forward-only.');
    }
};
