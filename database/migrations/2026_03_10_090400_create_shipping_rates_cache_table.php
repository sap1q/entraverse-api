<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rates_cache', function (Blueprint $table): void {
            $table->id();
            $table->char('origin_city_id', 4);
            $table->char('destination_city_id', 4);
            $table->string('courier', 50);
            $table->string('service', 50)->nullable();
            $table->unsignedInteger('weight');
            $table->unsignedInteger('cost');
            $table->string('etd', 20)->nullable();
            $table->timestamp('cached_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();

            $table->foreign('origin_city_id')
                ->references('id')
                ->on('cities')
                ->restrictOnDelete();

            $table->foreign('destination_city_id')
                ->references('id')
                ->on('cities')
                ->restrictOnDelete();

            $table->index(
                ['origin_city_id', 'destination_city_id', 'courier', 'weight'],
                'shipping_rates_cache_idx_lookup'
            );
            $table->index('expires_at', 'shipping_rates_cache_idx_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates_cache');
    }
};

