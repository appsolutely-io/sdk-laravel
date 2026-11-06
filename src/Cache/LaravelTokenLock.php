<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Cache;

use Appsolutely\Sdk\Cache\TokenLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;

/**
 * The SDK's lock around fetching the backend's token, taken with
 * Cache::lock() in the configured store, so the processes that share the
 * token through that store ask for a new one once rather than each. The
 * lock is released when $fetch returns or throws.
 *
 * A store without locks leaves every process to fetch its own, as the SDK
 * does without a lock.
 *
 * @internal bound to Appsolutely\Sdk\Cache\TokenLock by the service provider
 */
final readonly class LaravelTokenLock implements TokenLock
{
    public function __construct(
        private Repository $cache,
    ) {}

    public function run(string $name, int $seconds, callable $fetch): mixed
    {
        $lock = $this->lock($name, $seconds);
        if ($lock === null) {
            return $fetch();
        }

        try {
            return $lock->block($seconds, $fetch);
        } catch (LockTimeoutException) {
            // Still held after the wait: a second token request is
            // harmless, a call that fails for want of a token is not.
            return $fetch();
        }
    }

    public function runIfFree(string $name, int $seconds, callable $fetch): ?string
    {
        $lock = $this->lock($name, $seconds);
        if ($lock === null) {
            return $fetch();
        }

        // get() runs $fetch only when the lock is had, releasing it after,
        // and answers false at once when another process holds it.
        $fetched = $lock->get($fetch);

        return is_string($fetched) ? $fetched : null;
    }

    private function lock(string $name, int $seconds): ?Lock
    {
        $store = $this->cache->getStore();

        return $store instanceof LockProvider ? $store->lock($name, $seconds) : null;
    }
}
