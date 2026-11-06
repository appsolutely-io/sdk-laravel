<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * Laravel's ConnectionException as the PSR-18 exception the SDK catches and
 * reports as its own TransportException.
 *
 * @internal
 */
final class NetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
