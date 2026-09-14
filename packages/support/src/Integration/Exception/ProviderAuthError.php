<?php

declare(strict_types=1);

namespace Core\Support\Integration\Exception;

/**
 * Authentication / authorization failure from the provider (401, 403).
 *
 * Distinct from `ProviderTransientError` because the remediation is
 * different: refreshing credentials, rotating a key, fixing the client's
 * authorization scope — not retrying blindly. Surfaced so the caller can
 * take the appropriate action (e.g. the bearer provider refreshes its token
 * proactively to avoid this; an operator configures a missing key).
 */
final class ProviderAuthError extends ProviderException {}
