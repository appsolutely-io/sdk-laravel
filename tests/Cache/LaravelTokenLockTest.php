<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Cache;

use Appsolutely\Sdk\Cache\TokenLock;
use Appsolutely\Sdk\Laravel\Cache\LaravelTokenLock;
use Appsolutely\Sdk\Laravel\Tests\Support\StoreWithoutLocks;
use Appsolutely\Sdk\Laravel\Tests\TestCase;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use RuntimeException;

final class LaravelTokenLockTest extends TestCase
{
    private const string NAME = 'appsolutely.backend-token.lock';

    public function testRunHoldsTheLockWhileFetchingAndReleasesIt(): void
    {
        $heldDuring = null;

        $result = $this->lock()->run(self::NAME, 30, function () use (&$heldDuring): string {
            $heldDuring = $this->otherLock()->get() === false;

            return 'token-1';
        });

        self::assertSame('token-1', $result);
        self::assertTrue($heldDuring);
        self::assertTrue($this->otherLock()->get(), 'The lock was not released.');
    }

    public function testRunWaitsForAHeldLockThenFetchesAnyway(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $start = $this->freezeTime();
        self::assertTrue($this->otherLock()->get());

        $result = $this->lock()->run(self::NAME, 5, static fn(): string => 'token-1');

        // It waited for the lock up to the seconds given, then fetched without it.
        self::assertSame('token-1', $result);
        $waited = $start->diffInMilliseconds(now());
        self::assertGreaterThanOrEqual(4_000, $waited);
        self::assertLessThanOrEqual(5_000, $waited);
    }

    public function testRunReleasesTheLockWhenTheFetchFails(): void
    {
        try {
            $this->lock()->run(self::NAME, 30, $this->failingFetch(...));
            self::fail('The failure was swallowed.');
        } catch (RuntimeException) {
        }

        self::assertTrue($this->otherLock()->get(), 'The lock was not released.');
    }

    public function testRunIfFreeFetchesUnderTheLockAndReleasesIt(): void
    {
        $heldDuring = null;

        $result = $this->lock()->runIfFree(self::NAME, 30, function () use (&$heldDuring): string {
            $heldDuring = $this->otherLock()->get() === false;

            return 'token-1';
        });

        self::assertSame('token-1', $result);
        self::assertTrue($heldDuring);
        self::assertTrue($this->otherLock()->get(), 'The lock was not released.');
    }

    public function testRunIfFreeReturnsNullAtOnceWhenTheLockIsHeld(): void
    {
        Sleep::fake();
        self::assertTrue($this->otherLock()->get());
        $fetched = false;

        $result = $this->lock()->runIfFree(self::NAME, 30, static function () use (&$fetched): string {
            $fetched = true;

            return 'token-1';
        });

        self::assertNull($result);
        self::assertFalse($fetched);
        Sleep::assertNeverSlept();
    }

    public function testRunIfFreeReleasesTheLockWhenTheFetchFails(): void
    {
        try {
            $this->lock()->runIfFree(self::NAME, 30, $this->failingFetch(...));
            self::fail('The failure was swallowed.');
        } catch (RuntimeException) {
        }

        self::assertTrue($this->otherLock()->get(), 'The lock was not released.');
    }

    public function testTheLockIsTakenInTheConfiguredCacheStore(): void
    {
        config()->set('cache.stores.appsolutely', ['driver' => 'array']);
        config()->set('appsolutely.cache_store', 'appsolutely');
        $store = Cache::store('appsolutely')->getStore();
        self::assertInstanceOf(LockProvider::class, $store);
        self::assertTrue($store->lock(self::NAME, 30)->get());

        self::assertNull($this->lock()->runIfFree(self::NAME, 30, static fn(): string => 'token-1'));
        self::assertTrue($this->otherLock()->get(), 'The default store\'s lock was taken instead.');
    }

    public function testAStoreWithoutLocksStillRunsTheFetch(): void
    {
        Cache::extend('without-locks', fn(): Repository => Cache::repository(new StoreWithoutLocks()));
        config()->set('cache.stores.nolocks', ['driver' => 'without-locks']);
        config()->set('appsolutely.cache_store', 'nolocks');

        self::assertSame('token-1', $this->lock()->run(self::NAME, 30, static fn(): string => 'token-1'));
        self::assertSame('token-2', $this->lock()->runIfFree(self::NAME, 30, static fn(): string => 'token-2'));
    }

    private function failingFetch(): string
    {
        throw new RuntimeException('The token endpoint failed.');
    }

    private function lock(): TokenLock
    {
        $lock = $this->app?->make(TokenLock::class);
        self::assertInstanceOf(LaravelTokenLock::class, $lock);

        return $lock;
    }

    /** The same lock, as another process would take it. */
    private function otherLock(): Lock
    {
        $store = Cache::store()->getStore();
        self::assertInstanceOf(LockProvider::class, $store);

        return $store->lock(self::NAME, 30);
    }
}
