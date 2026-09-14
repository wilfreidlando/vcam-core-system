<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fitness functions 1, 1b and 2 — enforced on declared metadata rather than on
 * code references.
 *
 * Deptrac checks what the code actually does. This checks what the modules
 * *claim*: a module whose composer.json requires `core/payment` has already
 * made an architectural decision, even before a single `use` statement exists.
 * Catching it here makes the violation visible in review, at the moment it is
 * introduced.
 *
 * It also closes Deptrac's blind spot: a brand-new package directory that no
 * layer claims would otherwise be silently unanalysed.
 */
#[Group('arch')]
final class ComposerGraphTest extends TestCase
{
    private const PACKAGES_DIR = __DIR__.'/../../packages';

    #[Test]
    public function every_package_directory_is_declared_in_the_module_graph(): void
    {
        $onDisk = $this->packageDirectories();
        $declared = ModuleGraph::modules();

        sort($onDisk);
        sort($declared);

        self::assertSame(
            $declared,
            $onDisk,
            "The packages/ directory and the module graph disagree.\n".
            "A new bounded context must be added to tests/Architecture/ModuleGraph.php and to\n".
            "deptrac-modules.yaml, with an ADR recording the architecture committee's decision.\n".
            'Otherwise it is invisible to every boundary check.'
        );
    }

    #[Test]
    public function every_module_is_a_deptrac_layer(): void
    {
        $config = $this->deptracModules();

        $layers = array_map(
            static fn (array $layer): string => $layer['name'],
            $config['deptrac']['layers'],
        );

        $expected = array_values(ModuleGraph::LAYER_NAMES);

        sort($layers);
        sort($expected);

        self::assertSame(
            $expected,
            $layers,
            'deptrac-modules.yaml layers do not match the module graph. '.
            'An unlisted module is an unanalysed module.'
        );
    }

    #[Test]
    public function deptrac_ruleset_matches_the_module_graph(): void
    {
        $ruleset = $this->deptracModules()['deptrac']['ruleset'];

        foreach (ModuleGraph::ALLOWED as $module => $allowed) {
            $layer = ModuleGraph::LAYER_NAMES[$module];

            // The YAML value is untyped at this point (could be a malformed entry,
            // not just the list<string>|null the file is supposed to contain), so
            // both checks are load-bearing rather than redundant.
            $actual = $ruleset[$layer] ?? [];
            $actual = is_array($actual) ? array_values(array_filter($actual, 'is_string')) : [];

            $expected = array_map(
                static fn (string $dependency): string => ModuleGraph::LAYER_NAMES[$dependency],
                $allowed,
            );

            sort($actual);
            sort($expected);

            self::assertSame(
                $expected,
                $actual,
                sprintf(
                    'deptrac-modules.yaml allows %s to depend on [%s] but the module graph says [%s].',
                    $layer,
                    implode(', ', $actual),
                    implode(', ', $expected),
                ),
            );
        }
    }

    /**
     * G3c is written as one PHPat rule per module. A module added to the graph
     * without its rule would be free to reach into every other module.
     */
    #[Test]
    public function every_module_is_covered_by_the_contracts_only_rule(): void
    {
        $rules = \Core\Tooling\PhpStan\Architecture\ModulesMeetOnlyThroughContracts::class;

        foreach (ModuleGraph::modules() as $module) {
            if ($module === 'support') {
                continue;
            }

            $method = 'test_'.str_replace('-', '_', $module).'_uses_other_modules_through_contracts_only';

            self::assertTrue(
                method_exists($rules, $method),
                sprintf(
                    "Module %s has no %s::%s().\n".
                    "Without it nothing stops %s from reaching into another module's Domain or ".
                    'Infrastructure (guardrail G3c).',
                    $module,
                    $rules,
                    $method,
                    $module,
                ),
            );
        }
    }

    #[Test]
    public function module_manifests_declare_only_allowed_dependencies(): void
    {
        foreach (ModuleGraph::ALLOWED as $module => $allowed) {
            $manifest = $this->manifest($module);

            $declared = array_values(array_filter(
                array_keys($manifest['require'] ?? []),
                static fn (string $package): bool => str_starts_with($package, 'core/'),
            ));

            $declared = array_map(
                static fn (string $package): string => substr($package, strlen('core/')),
                $declared,
            );

            $forbidden = array_diff($declared, $allowed);

            self::assertSame(
                [],
                array_values($forbidden),
                sprintf(
                    "packages/%s/composer.json requires [%s], which the module graph forbids.\n".
                    'Allowed for %s: [%s].',
                    $module,
                    implode(', ', $forbidden),
                    $module,
                    implode(', ', $allowed),
                ),
            );
        }
    }

    /**
     * The rule that protects the financial core, asserted on its own so that a
     * failure names it explicitly.
     */
    #[Test]
    public function the_ledger_depends_on_nothing_but_the_shared_kernel(): void
    {
        self::assertSame(
            ['support'],
            ModuleGraph::ALLOWED['ledger'],
            'The Ledger is the financial core and sits at the bottom of the stack. '.
            'If it needs something from another module, the dependency is pointing '.
            'the wrong way: invert it with a port, or move the logic out of the ledger.'
        );

        $declared = array_keys($this->manifest('ledger')['require'] ?? []);

        self::assertSame(
            ['php', 'core/support'],
            $declared,
            'packages/ledger/composer.json must require nothing beyond PHP and the shared kernel.'
        );
    }

    /**
     * @return list<string>
     */
    private function packageDirectories(): array
    {
        $entries = scandir(self::PACKAGES_DIR);
        self::assertIsArray($entries);

        return array_values(array_filter(
            $entries,
            static fn (string $entry): bool => $entry !== '.'
                && $entry !== '..'
                && is_dir(self::PACKAGES_DIR.'/'.$entry),
        ));
    }

    /**
     * @return array{require?: array<string, string>}
     */
    private function manifest(string $module): array
    {
        $path = self::PACKAGES_DIR.'/'.$module.'/composer.json';

        self::assertFileExists($path, sprintf('Module %s has no composer.json.', $module));

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array{require?: array<string, string>} $decoded */
        return $decoded;
    }

    /**
     * @return array{deptrac: array{layers: list<array{name: string}>, ruleset: array<string, mixed>}}
     */
    private function deptracModules(): array
    {
        $path = __DIR__.'/../../deptrac-modules.yaml';
        self::assertFileExists($path);

        $parsed = \Symfony\Component\Yaml\Yaml::parseFile($path);
        self::assertIsArray($parsed);

        /** @var array{deptrac: array{layers: list<array{name: string}>, ruleset: array<string, mixed>}} $parsed */
        return $parsed;
    }
}
