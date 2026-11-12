<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specialists', function (Blueprint $table): void {
            $table->string('profile_image_path', 512)->nullable()->after('display_name');
            $table->string('specialization', 160)->nullable()->after('profile_image_path');
            $table->smallInteger('practices_since_year')->nullable()->after('specialization');
            $table->text('qualification')->nullable()->after('practices_since_year');
            $table->text('bio')->nullable()->after('qualification');
            $table->string('country', 100)->nullable()->after('bio');
            $table->string('city', 100)->nullable()->after('country');
            $table->string('phone', 32)->nullable()->after('city');
            $table->string('email', 320)->nullable()->after('phone');
            $table->string('telegram', 255)->nullable()->after('email');
            $table->boolean('phone_visible')->default(false)->after('telegram');
            $table->boolean('email_visible')->default(false)->after('phone_visible');
            $table->boolean('telegram_visible')->default(false)->after('email_visible');
        });
    }

    public function down(): void
    {
        Schema::table('specialists', function (Blueprint $table): void {
            $table->dropColumn([
                'profile_image_path',
                'specialization',
                'practices_since_year',
                'qualification',
                'bio',
                'country',
                'city',
                'phone',
                'email',
                'telegram',
                'phone_visible',
                'email_visible',
                'telegram_visible',
            ]);
        });
    }
};
