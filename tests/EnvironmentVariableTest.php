<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests;

use Appsolutely\Sdk\Api\RetryPolicy;
use Appsolutely\Sdk\Config;
use Appsolutely\Sdk\Laravel\EnvironmentVariable;
use Appsolutely\Sdk\Oidc\ClientAuthentication;
use Appsolutely\Sdk\Webhooks\Verifier;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;

final class EnvironmentVariableTest extends TestCase
{
    /**
     * @return iterable<string, array{EnvironmentVariable}>
     */
    public static function variables(): iterable
    {
        foreach (EnvironmentVariable::cases() as $variable) {
            yield $variable->value => [$variable];
        }
    }

    #[DataProvider('variables')]
    public function testTheConfigurationFileFillsTheVariablesSettingFromIt(EnvironmentVariable $variable): void
    {
        $value = 'from-' . $variable->value;
        $_ENV[$variable->value] = $value;
        $_SERVER[$variable->value] = $value;

        try {
            $config = require __DIR__ . '/../config/appsolutely.php';
        } finally {
            unset($_ENV[$variable->value], $_SERVER[$variable->value]);
        }

        self::assertIsArray($config);
        self::assertSame($value, Arr::get($config, $variable->configKey()));
    }

    public function testThePublishedConfigurationNamesNoClassOfThisPackageOrTheSdk(): void
    {
        // A published copy outlives the package versions it came from, so a
        // class it named could be given another name under it and fail every boot.
        self::assertStringNotContainsString('Appsolutely\\Sdk', self::configurationSource());
    }

    public function testEveryVariableTheConfigurationReadsIsACase(): void
    {
        preg_match_all("/env\\('([^']+)'/", self::configurationSource(), $matches);

        $cases = array_map(static fn(EnvironmentVariable $variable): string => $variable->value, EnvironmentVariable::cases());
        self::assertEqualsCanonicalizing($cases, $matches[1]);
    }

    public function testAnUnsetVariableTakesTheSdksDefault(): void
    {
        $config = require __DIR__ . '/../config/appsolutely.php';

        self::assertIsArray($config);
        self::assertSame(ClientAuthentication::ClientSecretBasic->value, $config['client_authentication']);
        self::assertSame(Config::DEFAULT_CLOCK_LEEWAY, $config['clock_leeway']);
        self::assertSame(Verifier::DEFAULT_TOLERANCE, Arr::get($config, 'webhooks.tolerance'));
        self::assertSame(Config::DEFAULT_TIMEOUT, Arr::get($config, 'http.timeout'));
        self::assertSame(Config::DEFAULT_CONNECT_TIMEOUT, Arr::get($config, 'http.connect_timeout'));
        $retries = new RetryPolicy();
        self::assertSame($retries->maxRetries, Arr::get($config, 'http.retries.max'));
        self::assertSame($retries->baseDelay, Arr::get($config, 'http.retries.base_delay'));
        self::assertSame($retries->maxDelay, Arr::get($config, 'http.retries.max_delay'));
    }

    private static function configurationSource(): string
    {
        $source = file_get_contents(__DIR__ . '/../config/appsolutely.php');
        self::assertIsString($source);

        return $source;
    }
}
