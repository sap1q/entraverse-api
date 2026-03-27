<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use RuntimeException;

class MarketplaceIntegrationException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
