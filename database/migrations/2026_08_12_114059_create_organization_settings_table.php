<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('setting_key', 100);
            $table->enum('value_type', ['string', 'integer', 'boolean']);
            $table->text('string_value')->nullable();
            $table->bigInteger('integer_value')->nullable();
            $table->boolean('boolean_value')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'setting_key']);
            $table->index(['organization_id', 'value_type']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE organization_settings ADD CONSTRAINT organization_settings_typed_value_check CHECK ((value_type = 'string' AND string_value IS NOT NULL AND integer_value IS NULL AND boolean_value IS NULL) OR (value_type = 'integer' AND string_value IS NULL AND integer_value IS NOT NULL AND boolean_value IS NULL) OR (value_type = 'boolean' AND string_value IS NULL AND integer_value IS NULL AND boolean_value IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_settings');
    }
};
