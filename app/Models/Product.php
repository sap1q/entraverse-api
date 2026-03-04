<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory, HasUuids;

    // UUID Setup
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name', 
        'category',
        'category_id',
        'brand', 
        'description', 
        'stock',
        'trade_in',
        'inventory',      // JSONB: Tempat stok & harga utama
        'photos',         // JSONB: Array text
        'variants',       // JSONB: Array opsi (warna, size, dll)
        'variant_pricing', // JSONB: Harga spesifik per varian
        'mekari_status',  // JSONB: Status sinkronisasi manual/auto
        'spu',            // Text: Kode unik produk
        'product_status', // Text: active, pending_approval, inactive
        'created_by', 
        'updated_by'
    ];

    // Default value agar tidak null saat insert
    protected $attributes = [
        'photos' => '[]',
        'variants' => '[]',
        'variant_pricing' => '[]',
        'inventory' => '{}',
        'mekari_status' => '{}',
        'trade_in' => false,
        'product_status' => 'active',
    ];

    // Casting JSONB ke Array agar bisa langsung dimanipulasi di Next.js
    protected $casts = [
        'inventory' => 'array',
        'photos' => 'array',
        'variants' => 'array',
        'variant_pricing' => 'array',
        'mekari_status' => 'array',
        'trade_in' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Helper: Mendapatkan stok total dari JSONB inventory
     * Contoh penggunaan di Dashboard: $product->total_stock
     */
    public function getTotalStockAttribute()
    {
        return $this->inventory['total_stock'] ?? 0;
    }

    public function productVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function stockMutations(): HasMany
    {
        return $this->hasMany(StockMutation::class);
    }

    public function salesOrderItems(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
