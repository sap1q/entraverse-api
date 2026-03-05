<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProductSyncedToJurnal
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $jurnalResponse
     */
    public function __construct(
        public Product $product,
        public array $jurnalResponse
    ) {}
}
