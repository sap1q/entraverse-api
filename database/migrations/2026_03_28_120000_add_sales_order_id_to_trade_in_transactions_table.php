<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_in_transactions', function (Blueprint $table): void {
            $table->uuid('sales_order_id')->nullable()->after('requested_product_variant_sku');
            $table->index('sales_order_id');
            $table->foreign('sales_order_id')->references('id')->on('sales_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trade_in_transactions', function (Blueprint $table): void {
            $table->dropForeign(['sales_order_id']);
            $table->dropIndex(['sales_order_id']);
            $table->dropColumn('sales_order_id');
        });
    }
};
