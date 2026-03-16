<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_origins', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('label', 100);
            $table->string('recipient_name', 120);
            $table->string('recipient_phone', 30);
            $table->char('province_id', 2);
            $table->char('city_id', 4);
            $table->char('district_id', 7)->nullable();
            $table->string('subdistrict', 120)->nullable();
            $table->text('address_detail');
            $table->string('zip_code', 5)->nullable();
            $table->string('location_note', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('province_id')
                ->references('id')
                ->on('provinces')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('city_id')
                ->references('id')
                ->on('cities')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('district_id')
                ->references('id')
                ->on('districts')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->index('is_active', 'store_origins_idx_active');
            $table->index('city_id', 'store_origins_idx_city');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_origins');
    }
};
