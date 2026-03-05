<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\ProductSyncedToJurnal;
use App\Listeners\HandleProductSync;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        ProductSyncedToJurnal::class => [
            HandleProductSync::class,
        ],
    ];
}
