<?php

declare(strict_types=1);

namespace Tests\Architecture\SecretLeak;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionProperty;
use SplFileInfo;

/**
 * The static half of G14: what the code DECLARES it can hand out.
 *
 *  - every public property of the data transfer objects a module publishes
 *    (`Contracts/`) or hands to its own controllers (`Application/Dto/`);
 *  - every type some production method RETURNS — which is how the guard
 *    tells a response DTO from a request DTO without trusting class names;
 *  - every key a `JsonResource` would publish (there is none today; the
 *    scan keeps that from changing silently).
 */
final class ApiDtoInventory
{
    /** Relative to `packages/<module>/src/`. */
    private const DTO_DIRECTORIES = ['Contracts', 'Application/Dto'];

    /**
     * The public properties of every API DTO whose name looks like a
     * secret, as `FQCN::$property`.
     *
     * @return list<string>
     */
    public static function secretLookingFields(string $root): array
    {
        $fields = [];

        foreach (self::dtoFiles($root) as $file) {
            foreach (self::declaredClasses($file) as $class) {
                if (! class_exists($class)) {
                    continue;
                }

                foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                    if (SecretLeakDetector::looksSecretish($property->getName())) {
                        $fields[] = $class.'::$'.$property->getName();
                    }
                }
            }
        }

        $fields = array_values(array_unique($fields));
        sort($fields);

        return $fields;
    }

    /**
     * Every class name used as the return type of a production method or
     * function (`packages/<module>/src/` and `app/`), resolved to its FQCN.
     *
     * @return array<string, true>
     */
    public static function returnedTypes(string $root): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        $collector = new class extends NodeVisitorAbstract
        {
            /** @var array<string, true> */
            public array $returned = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\FunctionLike) {
                    $this->collect($node->getReturnType());
                }

                return null;
            }

            private function collect(?Node $type): void
            {
                if ($type instanceof Node\Name) {
                    $this->returned[$type->toString()] = true;
                } elseif ($type instanceof Node\NullableType) {
                    $this->collect($type->type);
                } elseif ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
                    foreach ($type->types as $inner) {
                        $this->collect($inner);
                    }
                }
            }
        };

        $traverser = new NodeTraverser(new NameResolver, $collector);

        $files = self::phpFilesIn($root.'/app');
        foreach (self::packageSources($root) as $source) {
            $files = [...$files, ...self::phpFilesIn($source)];
        }

        foreach ($files as $file) {
            $statements = $parser->parse(self::read($file));
            if ($statements !== null) {
                $traverser->traverse($statements);
            }
        }

        return $collector->returned;
    }

    /**
     * The secret-looking keys of every `JsonResource`/`ResourceCollection`
     * in the production code, as `path: key`.
     *
     * Read from the source, not from a booted resource: a resource needs a
     * model to render, and the guard must see every key it COULD publish.
     *
     * @return list<string>
     */
    public static function jsonResourceSecretKeys(string $root): array
    {
        $files = self::phpFilesIn($root.'/app');
        foreach (self::packageSources($root) as $source) {
            $files = [...$files, ...self::phpFilesIn($source)];
        }

        $found = [];

        foreach ($files as $file) {
            $source = self::read($file);

            if (preg_match('/\bextends\s+\\\\?(?:[\w\\\\]+\\\\)?(?:JsonResource|ResourceCollection)\b/', $source) !== 1) {
                continue;
            }

            preg_match_all('/[\'"]([A-Za-z0-9_]+)[\'"]\s*=>/', $source, $matches);

            foreach (array_unique($matches[1]) as $key) {
                if (SecretLeakDetector::looksSecretish($key)) {
                    $found[] = substr($file, strlen($root) + 1).': '.$key;
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function dtoFiles(string $root): array
    {
        $files = [];

        foreach (self::packageSources($root) as $source) {
            foreach (self::DTO_DIRECTORIES as $directory) {
                $files = [...$files, ...self::phpFilesIn($source.'/'.$directory)];
            }
        }

        return $files;
    }

    /**
     * The FQCNs of the classes (and enums) a file declares.
     *
     * @return list<string>
     */
    private static function declaredClasses(string $file): array
    {
        $source = self::read($file);

        $namespace = preg_match('/^namespace\s+([^;\s]+)\s*;/m', $source, $match) === 1 ? $match[1].'\\' : '';

        preg_match_all('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|enum)\s+(\w+)/m', $source, $matches);

        return array_map(static fn (string $name): string => $namespace.$name, $matches[1]);
    }

    /**
     * @return list<string>
     */
    private static function packageSources(string $root): array
    {
        $sources = glob($root.'/packages/*/src', GLOB_ONLYDIR);

        return $sources === false ? [] : $sources;
    }

    /**
     * @return list<string>
     */
    private static function phpFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function read(string $file): string
    {
        $contents = file_get_contents($file);

        return $contents === false ? '' : $contents;
    }
}
