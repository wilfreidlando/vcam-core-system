<?php

declare(strict_types=1);

namespace Core\Portal\Domain\Exception;

use RuntimeException;

/**
 * ADR-0085 §1 — the counters of the login by code cannot be read: the
 * action is refused rather than allowed unmetered.
 */
final class LoginThrottleUnavailable extends RuntimeException {}
