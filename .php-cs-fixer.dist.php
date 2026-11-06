<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = (new Finder())
    ->in([__DIR__ . '/config', __DIR__ . '/src', __DIR__ . '/tests'])
    ->append([__FILE__]);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // A numbered PER revision, so a fixer upgrade that moves the
        // unnumbered alias to a newer revision does not restyle the code.
        '@PER-CS3x0' => true,
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
        'no_unused_imports' => true,
    ])
    ->setFinder($finder);
