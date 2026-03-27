<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_DRAFT = 'draft';

    // UUID Setup
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'category',
        'category_id',
        'brand',
        'brand_id',
        'description',
        'stock',
        'trade_in',
        'inventory',      // JSONB: Tempat stok & harga utama
        'photos',         // JSONB: Array text
        'variants',       // JSONB: Array opsi (warna, size, dll)
        'variant_pricing', // JSONB: Harga spesifik per varian
        'mekari_status',  // JSONB: Status sinkronisasi manual/auto
        'jurnal_id',
        'jurnal_metadata',
        'last_synced_at',
        'spu',            // Text: Kode unik produk
        'product_status', // Text: active, pending_approval, inactive
        'status',         // Enum: active, inactive, draft
        'is_featured',    // Boolean: produk unggulan
        'stock_status',   // Enum: in_stock, out_of_stock, preorder
        'created_by',
        'updated_by',
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
        'status' => self::STATUS_ACTIVE,
        'is_featured' => false,
        'stock_status' => 'in_stock',
    ];

    // Casting JSONB ke Array agar bisa langsung dimanipulasi di Next.js
    protected $casts = [
        'inventory' => 'array',
        'photos' => 'array',
        'variants' => 'array',
        'variant_pricing' => 'array',
        'mekari_status' => 'array',
        'jurnal_metadata' => 'array',
        'trade_in' => 'boolean',
        'is_featured' => 'boolean',
        'last_synced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isPubliclyVisible(): bool
    {
        $status = strtolower(trim((string) ($this->status ?? '')));
        if ($status === '') {
            $legacy = strtolower(trim((string) ($this->product_status ?? '')));
            $status = match ($legacy) {
                'active' => self::STATUS_ACTIVE,
                'inactive' => self::STATUS_INACTIVE,
                default => self::STATUS_DRAFT,
            };
        }

        return $status === self::STATUS_ACTIVE;
    }

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

    public function marketplaceMappings(): HasMany
    {
        return $this->hasMany(MarketplaceMapping::class);
    }

    public function salesOrderItems(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brandModel(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }
}
