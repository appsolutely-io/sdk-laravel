<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Support;

use Illuminate\Cache\ArrayStore;
use RuntimeException;

/**
 * A cache store whose backend cannot be reached, as Redis that is down: every
 * read and write throws, with a message naming where it tried to connect.
 */
final class UnreachableStore extends ArrayStore
{
    public const string MESSAGE = 'Connection refused [tcp://cache.internal:6379]';

    public function get($key): mixed
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function put($key, $value, $seconds): bool
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
