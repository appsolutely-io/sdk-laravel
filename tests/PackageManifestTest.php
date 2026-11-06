<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    /**
     * The SDK has no release yet: its main branch is aliased 0.1.x-dev, so
     * this accepts it now and its first 0.1 release once there is one.
     */
    public function testItRequiresTheSdksFirstMinorLineFromItsMainBranch(): void
    {
        self::assertSame('^0.1@dev', self::manifest()['require']['appsolutely/sdk-php'] ?? null);
    }

    public function testItRequiresLaravelComponentsRatherThanTheWholeFramework(): void
    {
        $require = self::manifest()['require'];

        self::assertArrayNotHasKey('laravel/framework', $require);
        self::assertNotSame([], self::illuminateConstraints($require));
    }

    public function testEveryLaravelComponentAcceptsTheSameLaravelVersions(): void
    {
        $constraints = self::illuminateConstraints(self::manifest()['require']);

        self::assertCount(1, array_unique($constraints), 'The illuminate/* constraints differ: ' . json_encode($constraints, JSON_THROW_ON_ERROR));
    }

    /**
     * Only the testing trait uses it, so a production install need not
     * carry it; an application's tests have it through laravel/framework.
     */
    public function testLaravelsTestingComponentIsADevelopmentDependencyAndASuggestion(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        self::assertArrayNotHasKey('illuminate/testing', self::manifest()['require']);
        self::assertIsArray($manifest['require-dev'] ?? null);
        self::assertSame(array_values(array_unique(self::illuminateConstraints(self::manifest()['require'])))[0], $manifest['require-dev']['illuminate/testing'] ?? null);
        self::assertIsArray($manifest['suggest'] ?? null);
        self::assertArrayHasKey('illuminate/testing', $manifest['suggest']);
    }

    /**
     * @param array<string, string> $require
     * @return array<string, string>
     */
    private static function illuminateConstraints(array $require): array
    {
        return array_filter($require, static fn(string $package): bool => str_starts_with($package, 'illuminate/'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return array{require: array<string, string>}
     */
    private static function manifest(): array
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['require'] ?? null);

        $require = [];
        foreach ($manifest['require'] as $package => $constraint) {
            self::assertIsString($constraint);
            $require[(string) $package] = $constraint;
        }

        return ['require' => $require];
    }
}
