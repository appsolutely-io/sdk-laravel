<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Testing;

use PHPUnit\Framework\TestCase;

/**
 * A production install without illuminate/testing, simulated in a child
 * process whose autoloader refuses Illuminate\Testing: every class of the
 * package still loads, and the testing trait, the one part that needs the
 * component, says what to install.
 */
final class WithoutLaravelsTestingComponentTest extends TestCase
{
    private const string CHILD = <<<'PHP'
        $composer = require getenv('PACKAGE_ROOT') . '/vendor/autoload.php';
        $composer->unregister();
        spl_autoload_register(static function (string $class) use ($composer): void {
            if (!str_starts_with($class, 'Illuminate\\Testing\\')) {
                $composer->loadClass($class);
            }
        });

        $loaded = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(getenv('PACKAGE_ROOT') . '/src', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen(getenv('PACKAGE_ROOT') . '/src/'), -strlen('.php'));
            $name = 'Appsolutely\\Sdk\\Laravel\\' . str_replace('/', '\\', $relative);
            if ($name === 'Appsolutely\\Sdk\\Laravel\\Testing\\InteractsWithAppsolutelyWebhooks') {
                continue;
            }
            $loaded[$name] = class_exists($name) || interface_exists($name) || enum_exists($name) || trait_exists($name);
        }

        try {
            trait_exists('Appsolutely\\Sdk\\Laravel\\Testing\\InteractsWithAppsolutelyWebhooks');
            $trait = 'loaded';
        } catch (LogicException $exception) {
            $trait = $exception->getMessage();
        }

        echo json_encode(['testing' => class_exists('Illuminate\\Testing\\TestResponse'), 'loaded' => $loaded, 'trait' => $trait]);
        PHP;

    public function testThePackageLoadsAndTheTraitNamesWhatToInstall(): void
    {
        $result = self::runChild();

        self::assertFalse($result['testing'], 'The child process still loaded Illuminate\Testing.');
        self::assertNotSame([], $result['loaded']);
        self::assertSame([], array_keys(array_filter($result['loaded'], static fn(bool $loaded): bool => !$loaded)));
        self::assertStringContainsString('illuminate/testing', $result['trait']);
    }

    /**
     * @return array{testing: bool, loaded: array<string, bool>, trait: string}
     */
    private static function runChild(): array
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-r', self::CHILD],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: ['PACKAGE_ROOT' => dirname(__DIR__, 2)],
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, 'The child process failed: ' . $errors);
        self::assertIsString($output);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsBool($result['testing'] ?? null);
        self::assertIsArray($result['loaded'] ?? null);
        self::assertIsString($result['trait'] ?? null);

        /** @var array{testing: bool, loaded: array<string, bool>, trait: string} $result */
        return $result;
    }
}
