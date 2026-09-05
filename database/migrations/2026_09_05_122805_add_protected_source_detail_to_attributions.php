<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['pre_auth_attributions', 'client_attributions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->text('encrypted_source_detail')->nullable();
                $table->unsignedSmallInteger('source_detail_key_version')->nullable();
            });
        }
    }

    public function down(): void {}
};
