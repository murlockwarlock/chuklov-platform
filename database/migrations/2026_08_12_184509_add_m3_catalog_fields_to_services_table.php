<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->enum('catalog_type', ['service', 'physical_product', 'online_product'])
                ->default('service')
                ->after('is_active');
            $table->string('name_ru', 160)->nullable()->after('name');
            $table->string('name_en', 160)->nullable()->after('name_ru');
            $table->text('description_ru')->nullable()->after('summary');
            $table->text('description_en')->nullable()->after('description_ru');
            $table->string('category', 120)->nullable()->after('description_en');
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('category');
            $table->unsignedSmallInteger('buffer_minutes')->default(0)->after('duration_minutes');
            $table->json('formats')->nullable()->after('buffer_minutes');
            $table->unsignedBigInteger('price_minor')->nullable()->after('formats');
            $table->char('price_currency', 3)->nullable()->after('price_minor');
            $table->string('payment_policy', 64)->nullable()->after('price_currency');
            $table->index(['organization_id', 'category']);
            $table->index(['organization_id', 'catalog_type', 'is_active']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE services ADD CONSTRAINT services_duration_minutes_positive '
                .'CHECK (duration_minutes IS NULL OR duration_minutes > 0)'
            );
            DB::statement(
                'ALTER TABLE services ADD CONSTRAINT services_price_currency_pair '
                .'CHECK ((price_minor IS NULL AND price_currency IS NULL) '
                .'OR (price_minor IS NOT NULL AND price_currency IS NOT NULL))'
            );
            DB::statement(
                'ALTER TABLE services ADD CONSTRAINT services_price_currency_format '
                ."CHECK (price_currency IS NULL OR price_currency ~ '^[A-Z]{3}$')"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_duration_minutes_positive');
            DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_price_currency_pair');
            DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_price_currency_format');
        }

        Schema::table('services', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'category']);
            $table->dropIndex(['organization_id', 'catalog_type', 'is_active']);
            $table->dropColumn([
                'catalog_type',
                'name_ru',
                'name_en',
                'description_ru',
                'description_en',
                'category',
                'duration_minutes',
                'buffer_minutes',
                'formats',
                'price_minor',
                'price_currency',
                'payment_policy',
            ]);
        });
    }
};
