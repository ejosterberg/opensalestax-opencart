<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                  => true,
        '@PSR12:risky'            => true,
        'array_syntax'            => ['syntax' => 'short'],
        'no_unused_imports'       => true,
        'declare_strict_types'    => true,
        'native_function_invocation' => false,
        'strict_param'            => true,
        'strict_comparison'       => true,
    ])
    ->setFinder($finder);
