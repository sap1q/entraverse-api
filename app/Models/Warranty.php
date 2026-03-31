<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warranty extends Model
{
    use HasFactory;
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'product_id',
        'customer_name',
        'phone',
        'address',
        'invoice_number',
        'serial_number',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $keyword = trim((string) $term);
        if ($keyword === '') {
            return $query;
        }

        $like = '%' . strtolower($keyword) . '%';

        return $query->where(function (Builder $builder) use ($like): void {
            $builder
                ->whereRaw('LOWER(customer_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(invoice_number) LIKE ?', [$like])
                ->orWhereRaw('LOWER(serial_number) LIKE ?', [$like])
                ->orWhereRaw('LOWER(phone) LIKE ?', [$like])
                ->orWhereHas('product', function (Builder $productQuery) use ($like): void {
                    $productQuery
                        ->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(spu) LIKE ?', [$like]);
                });
        });
    }
}
