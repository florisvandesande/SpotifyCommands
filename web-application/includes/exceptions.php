<?php

declare(strict_types=1);

final class AppException extends RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $public_code,
        public readonly string $public_message,
        public readonly int $http_status = 500,
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($public_message, 0, $previous);
    }
}
