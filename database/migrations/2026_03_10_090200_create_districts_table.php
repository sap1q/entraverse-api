<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table): void {
            $table->char('id', 7)->primary();
            $table->char('city_id', 4);
            $table->string('name', 100);
            $table->timestamps();

            $table->foreign('city_id')
                ->references('id')
                ->on('cities')
                ->restrictOnDelete();

            $table->index('city_id', 'districts_idx_city');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('districts');
    }
};

