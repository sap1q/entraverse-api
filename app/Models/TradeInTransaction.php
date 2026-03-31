<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradeInTransaction extends Model
{
    use HasFactory;
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'transaction_number',
        'user_id',
        'requested_product_id',
        'requested_product_name',
        'requested_product_variant_sku',
        'sales_order_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'customer_city',
        'customer_address',
        'trade_in_only',
        'device_brand',
        'device_model',
        'device_variant',
        'physical_condition',
        'device_age',
        'service_history',
        'accessory_summary',
        'answers',
        'estimated_amount',
        'offered_amount',
        'status',
        'fulfillment_method',
        'shipment_courier',
        'shipment_tracking_number',
        'customer_notes',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
        'completed_at',
    ];

    protected $casts = [
        'trade_in_only' => 'boolean',
        'accessory_summary' => 'array',
        'answers' => 'array',
        'estimated_amount' => 'float',
        'offered_amount' => 'float',
        'reviewed_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requestedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'requested_product_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TradeInTransactionPhoto::class)->orderBy('sort_order')->orderBy('created_at');
    }
}
