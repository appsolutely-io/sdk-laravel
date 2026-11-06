<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Http;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * A request Laravel's HTTP client did not send at all, such as one
 * Http::preventStrayRequests() refused, as the PSR-18 exception the SDK
 * catches and reports as its own TransportException.
 *
 * @internal
 */
final class ClientException extends RuntimeException implements ClientExceptionInterface {}
