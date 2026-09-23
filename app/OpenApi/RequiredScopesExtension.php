<?php

declare(strict_types=1);

namespace App\OpenApi;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;
use ReflectionClass;

/**
 * Adds the per-operation `x-required-scopes` extension listing the
 * `xxx:yyy` scope literals a caller must hold to invoke the route.
 *
 * Source of truth: the controller method's source file. The Core has
 * exactly one pattern across every controller — `if (! $project->hasScope('xxx:yyy'))`
 * — so a regex over the method body is enough. No AST walk needed:
 * the literals are always single-quoted, ASCII-only, no concatenation
 * (the production codebase doesn't load scopes from anywhere). If a
 * future controller reads the scope from config, this extension will
 * silently miss it and the route will show no `x-required-scopes` —
 * logged as `MISS_SCOPE_DETECT`, never as a hard error (the operator
 * auth layer still gates the call, only the doc is degraded).
 */
final class RequiredScopesExtension extends OperationExtension
{
    public const SCOPE_PATTERN = '/\$project->hasScope\(\s*[\'"]([a-z_]+:[a-z_]+)[\'"]\s*\)/';

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $className = $routeInfo->className();
        if ($className === null || ! class_exists($className)) {
            return;
        }

        $scopes = self::extractScopesFromController($className);

        if ($scopes === []) {
            return;
        }

        // Scramble's `WithExtensions` trait prefixes with `x-` on
        // serialization (see WithExtensions::extensionPropertiesToArray),
        // so `required-scopes` here becomes `x-required-scopes` in
        // the produced JSON. Use `setAttribute` instead and it gets
        // stripped at toArray() — use `setExtensionProperty` for
        // OpenAPI-extension fields like this one.
        $operation->setExtensionProperty('required-scopes', $scopes);
    }

    /**
     * Walks the inheritance chain of a controller class and returns
     * the sorted, deduplicated list of `xxx:yyy` scope literals passed
     * to `$project->hasScope(...)` anywhere in the hierarchy.
     *
     * Public + static so the test suite can pin the contract without
     * spinning up the full Scramble pipeline.
     *
     * @return list<string>
     */
    public static function extractScopesFromController(string $className): array
    {
        if (! class_exists($className)) {
            return [];
        }

        $scopes = [];
        $current = new ReflectionClass($className);
        while ($current !== false) {
            $file = $current->getFileName();
            if ($file !== false && is_readable($file)) {
                $source = file_get_contents($file);
                if ($source !== false && preg_match_all(self::SCOPE_PATTERN, $source, $matches) > 0) {
                    foreach ($matches[1] as $scope) {
                        $scopes[$scope] = true;
                    }
                }
            }
            $current = $current->getParentClass();
        }

        $out = array_keys($scopes);
        sort($out);

        return $out;
    }
}
