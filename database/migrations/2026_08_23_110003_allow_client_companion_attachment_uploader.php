<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_attachments', function (Blueprint $table): void {
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable()->change();
        });
    }

    public function down(): void {}
};
