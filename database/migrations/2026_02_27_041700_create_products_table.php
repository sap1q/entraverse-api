<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');
        }

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('category', 100);
            $table->string('brand', 100)->nullable();
            $table->text('description')->nullable();
            $table->boolean('trade_in')->default(false);
            
            // JSONB Columns
            $table->jsonb('inventory')->default(json_encode([
                'price' => 0,
                'total_stock' => 0,
                'weight' => 0
            ]));
            $table->jsonb('photos')->default(json_encode([]));
            $table->jsonb('variants')->default(json_encode([]));
            $table->jsonb('variant_pricing')->default(json_encode([]));
            $table->jsonb('mekari_status')->default(json_encode([
                'sync_status' => 'pending',
                'last_sync' => null,
                'mekari_id' => null
            ]));
            
            $table->string('spu', 50)->nullable()->unique();
            $table->string('product_status', 20)->default('active');
            
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Regular Indexes (Ini aman)
            $table->index('name');
            $table->index(['category', 'brand']);
            $table->index('spu');
            $table->index('product_status');
            $table->index(['created_at', 'product_status']);
        });

        // Foreign keys
        Schema::table('products', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        // JSONB Indexes - PAKAI DB::statement() TERPISAH
        DB::statement('CREATE INDEX idx_products_price ON products ((inventory->>\'price\'))');
        DB::statement('CREATE INDEX idx_products_stock ON products ((inventory->>\'total_stock\'))');

        // Check constraint
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_product_status_check 
            CHECK (product_status IN ('active', 'pending_approval', 'inactive', 'archived'))");

        // Partial index
        DB::statement("CREATE INDEX idx_products_active ON products(id) 
            WHERE product_status = 'active'");
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
        });
        
        Schema::dropIfExists('products');
    }
};
