<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Integration\Provider\ProviderCode;

/**
 * Resolves which provider handles a given channel, for a given Project
 * (ADR-0038 §2). The caller never picks the provider directly — it picks
 * a `PaymentChannel`, and this port decides which adapter serves it.
 *
 * This is what lets two Projects use two different providers for the
 * same channel (one only has the MyCoolPay aggregator configured,
 * another later gets a direct MTN adapter) without either integration
 * changing a single line — and what lets a Project migrate from one
 * provider to another for a channel without the calling application
 * noticing.
 */
interface PaymentProviderRouter
{
    /**
     * @throws NoProviderForChannelException when no provider (project
     *                                       override or platform default) serves this channel.
     */
    public function resolveProvider(PaymentChannel $channel, string $projectId): ProviderCode;
}
