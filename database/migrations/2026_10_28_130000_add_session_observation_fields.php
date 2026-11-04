<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_sessions', function (Blueprint $table): void {
            $table->text('subjective_feelings')->nullable()->after('observations');
            $table->text('visual_posture_diagnostics')->nullable()->after('subjective_feelings');
        });
    }

    public function down(): void
    {
        throw new LogicException('Medical session observation columns are preserved by forward-only migrations.');
    }
};
