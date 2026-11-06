<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Support;

use Illuminate\Cache\ArrayStore;

/**
 * A cache store that refuses writes without throwing, as a file store on an
 * unwritable directory answers false.
 */
final class UnwritableStore extends ArrayStore
{
    public function put($key, $value, $seconds): bool
    {
        return false;
    }
}
