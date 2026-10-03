<?php

declare(strict_types=1);

namespace Core\Support\Integration\Transport;

/**
 * A transport that can be told which IP address to connect to for a
 * host, instead of resolving it again itself. Used by
 * `GuardedHttpTransport` so the address `OutboundUrlGuard` checked is the
 * one actually connected to (no DNS-rebinding window).
 */
interface PinnableHttpTransport extends HttpTransport
{
    public function pinnedTo(string $host, int $port, string $ip): HttpTransport;
}
