<?php

declare(strict_types=1);

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
|
| Unit tests get plain PHPUnit\Framework\TestCase — no Laravel bootstrap.
| That is a deliberate constraint, not a default: a Domain or Application
| unit test that needed the framework to run would mean the layer under
| test was not actually framework-free (G3b). Integration and Schema tests
| need the real application (and PostgreSQL — ADR-0003), so they extend
| Tests\TestCase, which boots it.
|
*/

uses(TestCase::class)
    ->in(
        '../packages/*/tests/Integration',
        'Integration',
        'Schema',
        'Invariants',
    );
