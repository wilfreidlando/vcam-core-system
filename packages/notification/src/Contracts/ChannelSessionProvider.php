<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * Optional capability: a provider that requires a linked device session
 * before it can send anything (WhatsApp Web-style providers such as
 * WAHA, unlike an API-key-only provider like a payment aggregator or an
 * email/SMS gateway).
 *
 * Deliberately NOT part of `NotificationChannelProvider` — send/status/
 * callback is a universal channel concept, session linking is not (an
 * SMS or email adapter has no such thing). A concrete adapter that needs
 * it implements both interfaces on the same class; callers resolve the
 * gateway through the existing `NotificationChannelProviderRegistry`
 * (same `ProviderCode`, same `provider.gateway` tag) and narrow with an
 * `instanceof` check before calling any method here.
 *
 * The Core records WHICH sessions exist and who owns them (ADR-0057:
 * `notification_channel_sessions`, created by an operator) but never their
 * state — whether a phone is linked stays the provider's business. This
 * keeps the wire contract stable across providers: swapping WAHA for a
 * different WhatsApp API later only means writing a new adapter.
 */
interface ChannelSessionProvider
{
    /**
     * Idempotent: creates the session on the provider if it doesn't
     * exist yet, starts it if stopped, and is a no-op if already
     * running. Called ONLY by the operator's explicit "create session"
     * action (`CreateChannelSession`) — never implicitly from a client
     * request. Whether a session exists FOR SENDING is decided by the
     * Core's own registry (`notification_channel_sessions`), never by
     * asking the provider.
     */
    public function startSession(string $sessionRef): void;

    /**
     * Removes the session at the provider (which also disconnects any linked
     * phone). Idempotent: a session the provider no longer has is not an
     * error.
     */
    public function deleteSession(string $sessionRef): void;

    public function requestPairingCode(string $sessionRef, string $phoneNumber): string;

    public function getQrCode(string $sessionRef): ChannelSessionQrCode;

    public function getScreenshot(string $sessionRef): ChannelSessionScreenshot;
}
