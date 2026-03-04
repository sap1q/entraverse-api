<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sales_order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('sales_order_id');
            $table->uuid('product_id');
            $table->string('product_name', 255);
            $table->string('variant_name', 255)->nullable();
            $table->string('variant_sku', 120);
            $table->string('warehouse', 120);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->decimal('landed_cost', 16, 2)->default(0);
            $table->decimal('line_total', 16, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'variant_sku']);
            $table->index('warehouse');

            $table->foreign('sales_order_id')->references('id')->on('sales_orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_order_items');
    }
};

