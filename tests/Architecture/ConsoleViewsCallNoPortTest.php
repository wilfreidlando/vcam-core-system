<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guard G16, second half (ADR-0069 §2).
 *
 * `AdminQueriesAreScopedRule` proves that every read port the console calls
 * takes the organization scope — but PHPStan never reads a Blade template.
 * A port called from a view would escape the guard entirely: the sidebar
 * once counted every tenant's money exceptions that way.
 *
 * So templates call no port at all. What a view needs comes from its
 * controller or from a view composer (`App\Http\View\Composers`), both
 * analysed by the rule. The only container lookup a template may make is
 * the request's `OperatorContext`, which is not a port: it holds the
 * account's identity and scope, it reads nothing.
 */
#[Group('arch')]
final class ConsoleViewsCallNoPortTest extends TestCase
{
    private const VIEWS = __DIR__.'/../../resources/views';

    /** The container lookups a template may make. */
    private const ALLOWED = [
        'Core\\Identity\\Contracts\\OperatorContext',
    ];

    #[Test]
    public function no_template_resolves_a_port_from_the_container(): void
    {
        $violations = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::VIEWS));
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // `app(X::class)`, `resolve(X::class)`, `app()->make(X::class)`,
            // `App::make(X::class)` — every way a template can reach the container.
            preg_match_all(
                '/(?:\bapp|\bresolve|->make|App::make)\(\s*\\\\?(Core\\\\[A-Za-z\\\\]+)::class/',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE,
            );

            foreach ($matches[1] as [$class, $offset]) {
                if (in_array($class, self::ALLOWED, true)) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $violations[] = sprintf('%s:%d resolves %s', substr($file->getPathname(), strlen(self::VIEWS) + 1), $line, $class);
            }
        }

        self::assertSame(
            [],
            $violations,
            "G16 — a template resolves a port from the container (ADR-0069 §2). PHPStan never reads a\n".
            "template, so a port called here escapes AdminQueriesAreScopedRule. Compute the value in the\n".
            "controller or in a view composer (App\\Http\\View\\Composers) and pass it to the view:\n  - ".
            implode("\n  - ", $violations),
        );
    }

    #[Test]
    public function the_guard_sees_a_port_resolved_in_a_template(): void
    {
        // The pattern above is the whole guard: prove it matches the shapes
        // it claims to, and leaves OperatorContext alone.
        $pattern = '/(?:\bapp|\bresolve|->make|App::make)\(\s*\\\\?(Core\\\\[A-Za-z\\\\]+)::class/';

        foreach ([
            '{{ app(\\Core\\Payment\\Contracts\\ListMoneyExceptions::class)->countUnresolved() }}',
            '@php $x = resolve(Core\\Payment\\Contracts\\CountStuckPayments::class); @endphp',
            '@php $x = app()->make(\\Core\\Wallet\\Contracts\\ListWallets::class); @endphp',
        ] as $template) {
            self::assertSame(1, preg_match($pattern, $template), $template);
        }

        preg_match($pattern, '@if(app(\\Core\\Identity\\Contracts\\OperatorContext::class)->hasPermissionAnywhere(\'x\'))', $match);
        self::assertSame('Core\\Identity\\Contracts\\OperatorContext', $match[1] ?? null);
    }
}
