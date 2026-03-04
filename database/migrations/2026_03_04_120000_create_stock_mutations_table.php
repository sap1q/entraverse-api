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
        Schema::create('stock_mutations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('product_id');
            $table->string('variant_sku', 120);
            $table->enum('type', ['in', 'out', 'adjustment']);
            $table->integer('quantity');
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'variant_sku']);
            $table->index('variant_sku');
            $table->index('created_at');

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('admins')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_mutations');
    }
};

