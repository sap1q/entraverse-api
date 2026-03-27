<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->unique();
            $table->string('invoice_number', 50)->unique();
            $table->string('payment_method', 120)->nullable();
            $table->string('payment_va_number', 120)->nullable();
            $table->string('payment_bill_key', 120)->nullable();
            $table->decimal('amount_total', 16, 2)->default(0);
            $table->enum('payment_status', ['pending', 'paid', 'expired', 'failed'])->default('pending')->index();
            $table->string('snap_token', 255)->nullable();
            $table->timestamp('expiry_time')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')
                ->references('id')
                ->on('sales_orders')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
