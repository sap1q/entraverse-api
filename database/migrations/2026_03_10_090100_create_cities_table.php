<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table): void {
            $table->char('id', 4)->primary();
            $table->char('province_id', 2);
            $table->string('name', 100);
            $table->string('type', 20);
            $table->string('postal_code', 5)->nullable();
            $table->timestamps();

            $table->foreign('province_id')
                ->references('id')
                ->on('provinces')
                ->restrictOnDelete();

            $table->index('province_id', 'cities_idx_province');
            $table->index('postal_code', 'cities_idx_postal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};

