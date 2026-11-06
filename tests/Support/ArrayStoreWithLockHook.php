<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Support;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;

/**
 * An array store that runs a hook whenever a lock is asked of it, to stage
 * what another process does between a check and the lock that follows it.
 */
final class ArrayStoreWithLockHook extends ArrayStore
{
    public ?Closure $beforeLock = null;

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        if ($this->beforeLock !== null) {
            ($this->beforeLock)();
        }

        return parent::lock($name, $seconds, $owner);
    }
}
