<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingRateCache extends Model
{
    use HasFactory;

    protected $table = 'shipping_rates_cache';

    public $timestamps = false;

    protected $fillable = [
        'origin_city_id',
        'destination_city_id',
        'courier',
        'service',
        'weight',
        'cost',
        'etd',
        'cached_at',
        'expires_at',
    ];

    protected $casts = [
        'weight' => 'integer',
        'cost' => 'integer',
        'cached_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function originCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'origin_city_id');
    }

    public function destinationCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'destination_city_id');
    }
}
