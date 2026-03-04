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
        Schema::create('sales_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('order_number', 40)->unique();
            $table->string('customer_name', 255);
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_email', 255)->nullable();
            $table->text('customer_address')->nullable();
            $table->enum('status', ['dibayar', 'diproses', 'dikirim', 'selesai', 'dibatalkan'])->default('dibayar');
            $table->string('currency', 3)->default('IDR');
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('shipping_cost', 16, 2)->default(0);
            $table->decimal('discount_amount', 16, 2)->default(0);
            $table->decimal('total_amount', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('customer_name');

            $table->foreign('created_by')->references('id')->on('admins')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('admins')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};

