<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // ADR-0123 — the Core encrypts nothing of the client's data any
    // more: it holds no key of its own for it, and there is nothing to
    // configure, nothing to rotate and nothing to lose in a dump.
}
