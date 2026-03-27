<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesOrder extends Model
{
    use HasFactory;
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'order_number',
        'user_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'customer_address',
        'status',
        'currency',
        'subtotal',
        'shipping_cost',
        'discount_amount',
        'total_amount',
        'notes',
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
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'subtotal' => 'float',
        'shipping_cost' => 'float',
        'discount_amount' => 'float',
        'total_amount' => 'float',
        'payment_payload' => 'array',
        'shipping_metadata' => 'array',
        'shipping_weight' => 'integer',
        'settled_at' => 'datetime',
        'jurnal_synced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
