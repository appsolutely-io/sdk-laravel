<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Webhooks;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Response;

/**
 * A lock per delivery id, taken with Cache::lock() in the store that keeps
 * the handled ids, so two attempts of one delivery arriving together do
 * not both run the listeners. Named under the deliveries namespace, as the
 * handled ids are, so endpoints sharing a store keep their locks apart.
 *
 * @internal used by HandleWebhook
 */
final readonly class DeliveryLock
{
    /**
     * Held at most this long, so an attempt whose process dies cannot keep
     * the delivery from being handled; well beyond the few seconds the
     * sender waits for an answer.
     */
    public const int SECONDS = 60;

    private const string PREFIX = 'appsolutely.webhooks.lock.';

    public function __construct(
        private Repository $cache,
        private string $namespace = '',
    ) {}

    /**
     * Runs $handle holding the delivery's lock, released when it returns
     * or throws; returns null without running it when another attempt
     * holds the lock. A store without locks runs it unguarded.
     *
     * @param Closure(): Response $handle
     */
    public function run(string $id, Closure $handle): ?Response
    {
        $lock = $this->lock($id);
        if ($lock === null) {
            return $handle();
        }

        $result = $lock->get($handle);

        return $result instanceof Response ? $result : null;
    }

    /**
     * The delivery's lock, not acquired; null when the store has no locks.
     */
    public function lock(string $id): ?Lock
    {
        $store = $this->cache->getStore();

        return $store instanceof LockProvider ? $store->lock($this->name($id), self::SECONDS) : null;
    }

    /**
     * Hashed, as the handled ids are: an id is the sender's text, of any
     * length, and the namespace's length keeps one pair from naming
     * another's lock.
     */
    private function name(string $id): string
    {
        return self::PREFIX . hash('sha256', strlen($this->namespace) . ':' . $this->namespace . $id);
    }
}
