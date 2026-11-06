<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Clock;

use DateTimeImmutable;
use Illuminate\Support\DateFactory;
use Psr\Clock\ClockInterface;

/**
 * Laravel's now() as a PSR-20 clock, so a test that freezes or travels
 * through time (travelTo(), freezeTime()) moves the SDK's clock too.
 *
 * @internal
 */
final readonly class LaravelClock implements ClockInterface
{
    public function __construct(
        private DateFactory $dates,
    ) {}

    public function now(): DateTimeImmutable
    {
        return $this->dates->now()->toDateTimeImmutable();
    }
}
