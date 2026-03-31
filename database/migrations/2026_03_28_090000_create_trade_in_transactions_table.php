<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_in_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('transaction_number', 40)->unique();
            $table->uuid('user_id')->nullable();
            $table->uuid('requested_product_id')->nullable();
            $table->string('requested_product_name', 255)->nullable();
            $table->string('requested_product_variant_sku', 120)->nullable();
            $table->string('customer_name', 255);
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_email', 255)->nullable();
            $table->string('customer_city', 120)->nullable();
            $table->text('customer_address')->nullable();
            $table->boolean('trade_in_only')->default(true);
            $table->string('device_brand', 120)->nullable();
            $table->string('device_model', 160)->nullable();
            $table->string('device_variant', 160)->nullable();
            $table->string('physical_condition', 80)->nullable();
            $table->string('device_age', 80)->nullable();
            $table->string('service_history', 80)->nullable();
            $table->json('accessory_summary')->nullable();
            $table->json('answers')->nullable();
            $table->decimal('estimated_amount', 16, 2)->default(0);
            $table->decimal('offered_amount', 16, 2)->default(0);
            $table->enum('status', [
                'menunggu_review',
                'disetujui',
                'ditolak',
                'menunggu_pengiriman',
                'dikirim_pelanggan',
                'kunjungan_toko',
                'selesai',
                'dibatalkan',
            ])->default('menunggu_review');
            $table->enum('fulfillment_method', ['belum_dipilih', 'pengiriman', 'offline_store'])
                ->default('belum_dipilih');
            $table->string('shipment_courier', 80)->nullable();
            $table->string('shipment_tracking_number', 120)->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index('customer_name');
            $table->index('requested_product_id');

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('requested_product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_in_transactions');
    }
};
