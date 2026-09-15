<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use RuntimeException;

/**
 * A channel session provider that addresses recipients by phone number
 * (WhatsApp) requires one. `OutboundMessage::recipientRef` is a pure
 * correlation id (G4 — `parties` carries no PII, ADR-0041 §5); the real
 * number must travel in `variables['phone_number']`, supplied by the
 * calling app at send time and never persisted by the Core. This
 * exception fires when that key is missing (ADR-0042 §7).
 */
final class MissingRecipientPhoneNumber extends RuntimeException
{
    public static function forMessage(string $appMessageRef): self
    {
        return new self(sprintf(
            'OutboundMessage "%s" is missing variables[\'phone_number\'] — required to address a WhatsApp recipient.',
            $appMessageRef,
        ));
    }
}
