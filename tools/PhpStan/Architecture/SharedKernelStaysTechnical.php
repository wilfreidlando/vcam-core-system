<?php

declare(strict_types=1);

namespace Core\Tooling\PhpStan\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Guardrail G3d — the shared kernel stays a set of technical primitives.
 *
 * Every module may depend on core/support and it may depend on nothing. That is
 * precisely the profile of the module that slowly absorbs "just one helper",
 * then a repository, then a business rule nobody knows where else to put
 * (architecture review, §3.3). Once business state lives in the shared kernel,
 * every module is coupled to it and the boundaries are gone.
 *
 * The complete list of what may live there is closed and documented in
 * ADR-0020 and packages/support/README.md. This rule enforces the part that can
 * be automated: no persistence of any kind.
 *
 * @see docs/adr/0020-shared-kernel-support.md
 */
final class SharedKernelStaysTechnical
{
    public function test_shared_kernel_holds_no_persistence(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Core\Support'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Illuminate\Database'),
                Selector::classname('Illuminate\Support\Facades\DB'),
                Selector::classname('Illuminate\Support\Facades\Schema'),
                Selector::classname('Illuminate\Support\Facades\Redis'),
            )
            ->because(
                'The shared kernel holds technical primitives only (ADR-0020). State belongs to '.
                'the module that owns it; a store the kernel needs is a port it declares and the '.
                'application shell implements.'
            );
    }
}
