<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_orders', 'user_id')) {
                $table->uuid('user_id')->nullable()->index();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('sales_orders', 'payment_method')) {
                $table->string('payment_method', 50)->nullable();
            }

            if (! Schema::hasColumn('sales_orders', 'payment_status')) {
                $table->string('payment_status', 40)->default('pending')->index();
            }

            if (! Schema::hasColumn('sales_orders', 'payment_reference')) {
                $table->string('payment_reference', 100)->nullable()->index();
            }

            if (! Schema::hasColumn('sales_orders', 'snap_token')) {
                $table->text('snap_token')->nullable();
            }

            if (! Schema::hasColumn('sales_orders', 'payment_payload')) {
                $table->json('payment_payload')->nullable();
            }

            if (! Schema::hasColumn('sales_orders', 'settled_at')) {
                $table->timestamp('settled_at')->nullable();
            }

            if (! Schema::hasColumn('sales_orders', 'shipping_courier')) {
                $table->string('shipping_courier', 50)->nullable();
            }

            if (! Schema::hasColumn('sales_orders', 'shipping_service')) {
                $table->string('shipping_service', 80)->nullable();
            }

            if (! Schema::hasColumn('sales_orders', 'shipping_etd')) {
                $table->string('shipping_etd', 50)->nullable();
            }

            if (! Schema::hasColumn('sales_orders', 'shipping_weight')) {
                $table->unsignedInteger('shipping_weight')->default(0);
            }

            if (! Schema::hasColumn('sales_orders', 'shipping_destination_city_id')) {
                $table->char('shipping_destination_city_id', 4)->nullable()->index();
                $table->foreign('shipping_destination_city_id')
                    ->references('id')
                    ->on('cities')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('sales_orders', 'shipping_metadata')) {
                $table->json('shipping_metadata')->nullable();
            }

            if (! Schema::hasColumn('sales_orders', 'jurnal_invoice_id')) {
                $table->string('jurnal_invoice_id', 120)->nullable()->index();
            }

            if (! Schema::hasColumn('sales_orders', 'jurnal_sync_status')) {
                $table->string('jurnal_sync_status', 30)->nullable()->index();
            }

            if (! Schema::hasColumn('sales_orders', 'jurnal_sync_message')) {
                $table->text('jurnal_sync_message')->nullable();
            }

            if (! Schema::hasColumn('sales_orders', 'jurnal_synced_at')) {
                $table->timestamp('jurnal_synced_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_orders', 'shipping_destination_city_id')) {
                $table->dropForeign(['shipping_destination_city_id']);
                $table->dropIndex(['shipping_destination_city_id']);
            }

            if (Schema::hasColumn('sales_orders', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropIndex(['user_id']);
            }

            if (Schema::hasColumn('sales_orders', 'payment_reference')) {
                $table->dropIndex(['payment_reference']);
            }

            if (Schema::hasColumn('sales_orders', 'payment_status')) {
                $table->dropIndex(['payment_status']);
            }

            if (Schema::hasColumn('sales_orders', 'jurnal_invoice_id')) {
                $table->dropIndex(['jurnal_invoice_id']);
            }

            if (Schema::hasColumn('sales_orders', 'jurnal_sync_status')) {
                $table->dropIndex(['jurnal_sync_status']);
            }

            $table->dropColumn([
                'user_id',
                'payment_method',
                'payment_status',
                'payment_reference',
                'snap_token',
                'payment_payload',
                'settled_at',
                'shipping_courier',
                'shipping_service',
                'shipping_etd',
                'shipping_weight',
                'shipping_destination_city_id',
                'shipping_metadata',
                'jurnal_invoice_id',
                'jurnal_sync_status',
                'jurnal_sync_message',
                'jurnal_synced_at',
            ]);
        });
    }
};

