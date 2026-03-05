<?php

declare(strict_types=1);

namespace App\Services\Mekari\Exceptions;

use RuntimeException;
use Throwable;

class MekariApiException extends RuntimeException
{
    protected readonly ?array $responseData;

    protected readonly int $statusCode;

    public function __construct(
        string $message = 'Mekari API request failed.',
        int $statusCode = 0,
        ?array $responseData = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->responseData = $responseData;
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?array
    {
        return $this->responseData;
    }

    public function getResponseData(): ?array
    {
        return $this->responseData;
    }
}
