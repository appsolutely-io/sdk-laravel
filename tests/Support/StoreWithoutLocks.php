<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Store;

/**
 * A cache store that keeps values but offers no locks, as a custom or
 * third-party store may: it implements Store and not LockProvider.
 */
final class StoreWithoutLocks implements Store
{
    private ArrayStore $values;

    public function __construct()
    {
        $this->values = new ArrayStore();
    }

    public function get($key): mixed
    {
        return $this->values->get($key);
    }

    /**
     * @param array<array-key, mixed> $keys
     * @return array<array-key, mixed>
     */
    public function many(array $keys): array
    {
        return $this->values->many($keys);
    }

    public function put($key, $value, $seconds): bool
    {
        return $this->values->put($key, $value, $seconds);
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public function putMany(array $values, $seconds): bool
    {
        return $this->values->putMany($values, $seconds);
    }

    public function increment($key, $value = 1): int
    {
        return $this->values->increment($key, $value);
    }

    public function decrement($key, $value = 1): int
    {
        return $this->values->decrement($key, $value);
    }

    public function forever($key, $value): bool
    {
        return $this->values->forever($key, $value);
    }

    /**
     * Laravel 13 adds this to Store; Laravel 12's ArrayStore has no touch()
     * to delegate to, so it is written with what both versions have.
     *
     * @param string $key
     * @param int $seconds
     */
    public function touch($key, $seconds): bool
    {
        $value = $this->values->get($key);

        return $value !== null && $this->values->put($key, $value, $seconds);
    }

    public function forget($key): bool
    {
        return $this->values->forget($key);
    }

    public function flush(): bool
    {
        return $this->values->flush();
    }

    public function getPrefix(): string
    {
        return '';
    }
}
