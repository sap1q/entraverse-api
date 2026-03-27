<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceConnection extends Model
{
    use HasFactory;
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'channel',
        'status',
        'shop_id',
        'shop_name',
        'seller_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'connected_at',
        'last_inbound_sync_at',
        'last_outbound_sync_at',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'last_inbound_sync_at' => 'datetime',
        'last_outbound_sync_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function mappings(): HasMany
    {
        return $this->hasMany(MarketplaceMapping::class);
    }
}
