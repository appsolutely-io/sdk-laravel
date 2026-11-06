<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Clock;

use Appsolutely\Sdk\Clock\Sleeper;
use Illuminate\Support\Sleep;

/**
 * The SDK's wait between Site API retries, through Laravel's Sleep, so a
 * test that calls Sleep::fake() does not wait and can assert the waits.
 *
 * @internal
 */
final readonly class LaravelSleeper implements Sleeper
{
    public function sleep(float $seconds): void
    {
        Sleep::usleep((int) round($seconds * 1_000_000));
    }
}
