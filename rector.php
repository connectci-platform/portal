<?php

/**
 * @file
 * Rector configuration for automated refactoring and type declarations.
 *
 * Run via `ddev rector` (dry-run/preview) or `ddev rector --fix` (apply).
 * See .ddev/commands/web/rector.
 */

declare(strict_types=1);

use DrupalRector\Set\Drupal10SetList;
use Rector\Config\RectorConfig;
use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\Function_\AddFunctionVoidReturnTypeWhereNoReturnRector;

return static function (RectorConfig $rectorConfig): void {
    // Only our custom code — never rewrite core, contrib, or vendor.
    $rectorConfig->paths([
        __DIR__ . '/web/modules/custom',
        __DIR__ . '/web/themes/custom',
    ]);

    // Drupal 10 API-deprecation rewrites (the reason drupal-rector exists).
    $rectorConfig->sets([
        Drupal10SetList::DRUPAL_10,
    ]);

    // Low-risk quality/type rules that address the common PHPStan level-6
    // gaps (missing void return types) without changing behavior.
    $rectorConfig->rules([
        AddVoidReturnTypeWhereNoReturnRector::class,
        AddFunctionVoidReturnTypeWhereNoReturnRector::class,
        InlineConstructorDefaultToPropertyRector::class,
    ]);

    // The Drupal root is the project's web/ directory. Autoload core + the
    // module/theme trees so rector can resolve Drupal classes for inference.
    $drupalRoot = __DIR__ . '/web';
    $rectorConfig->autoloadPaths([
        $drupalRoot . '/core',
        $drupalRoot . '/modules',
        $drupalRoot . '/profiles',
        $drupalRoot . '/themes',
    ]);

    // Drupal procedural files use non-.php extensions.
    $rectorConfig->fileExtensions(['php', 'module', 'theme', 'install', 'profile', 'inc', 'engine']);

    // Don't rewrite test fixtures/generated code.
    $rectorConfig->skip([
        '*/tests/fixtures/*',
        '*/node_modules/*',
    ]);

    $rectorConfig->importNames(true, false);
    $rectorConfig->importShortClasses(false);
};
