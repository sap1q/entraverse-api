<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceMapping extends Model
{
    use HasFactory;
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'marketplace_connection_id',
        'product_id',
        'channel',
        'variant_key',
        'seller_sku',
        'marketplace_product_id',
        'marketplace_sku_id',
        'status',
        'last_synced_at',
        'last_error',
        'payload',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'payload' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MarketplaceConnection::class, 'marketplace_connection_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
