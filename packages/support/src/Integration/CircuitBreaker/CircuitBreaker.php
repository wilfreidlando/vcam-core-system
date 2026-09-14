<?php

declare(strict_types=1);

namespace Core\Support\Integration\CircuitBreaker;

use Core\Support\Integration\Configuration\CircuitBreakerPolicy;
use Core\Support\Integration\Provider\ProviderCode;

/**
 * State machine that protects a provider against cascading failures.
 *
 * The breaker is keyed by `ProviderCode`. Implementations are stateful
 * (the in-memory default holds per-process counters; a Valkey-backed
 * implementation holds cluster-wide counters). The foundation provides
 * only the contract and an in-memory default; the application shell
 * rebinds to a Valkey impl when cluster-wide coordination is required.
 *
 * Implementations MUST be safe to call from any HTTP worker process.
 * Implementations MAY keep state in-process or out-of-process; the policy
 * describes the *behaviour*, this port describes the *interface*.
 */
interface CircuitBreaker
{
    /**
     * Throw `CircuitOpenException` if the breaker is currently open for
     * this provider. No-op when closed or half-open (and still has probes
     * available).
     *
     * @throws CircuitOpenException
     */
    public function guard(ProviderCode $code): void;

    /**
     * Notify the breaker that one attempt succeeded. May transition the
     * state from half-open back to closed.
     */
    public function recordSuccess(ProviderCode $code): void;

    /**
     * Notify the breaker that one attempt failed with a transient or
     * permanent error. May transition the state from closed to open,
     * or from half-open back to open.
     */
    public function recordFailure(ProviderCode $code): void;

    /**
     * Whether the breaker is currently in the open state for the provider.
     * Read-only; does not consume a probe.
     */
    public function isOpen(ProviderCode $code): bool;

    /**
     * Bind the policy that governs this breaker's behaviour. Called once
     * at boot when the registry is wired.
     */
    public function configure(CircuitBreakerPolicy $policy): void;
}
