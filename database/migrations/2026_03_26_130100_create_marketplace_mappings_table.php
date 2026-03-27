<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('marketplace_connection_id')->nullable()->constrained('marketplace_connections')->nullOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('channel', 30)->index();
            $table->string('variant_key', 160)->index();
            $table->string('seller_sku', 160)->nullable();
            $table->string('marketplace_product_id', 160)->nullable();
            $table->string('marketplace_sku_id', 160)->nullable();
            $table->string('status', 30)->default('mapped')->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'product_id', 'variant_key'], 'marketplace_mappings_channel_product_variant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_mappings');
    }
};
