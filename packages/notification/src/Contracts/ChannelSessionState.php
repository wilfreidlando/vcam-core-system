<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * Canonical state of a provider-side messaging session — the only shape in
 * which a provider's own vocabulary ("WORKING", "SCAN_QR_CODE"…) leaves its
 * adapter. Read live from the provider, never persisted by the Core.
 */
enum ChannelSessionState: string
{
    /** A phone is linked and the session can send. */
    case Linked = 'linked';

    /** Started, waiting for a QR scan / pairing code. */
    case AwaitingLink = 'awaiting_link';

    /** Stopped: nothing can be sent until it is started again. */
    case Stopped = 'stopped';

    /** The provider reports the session as failed. */
    case Failed = 'failed';

    /** Registered in the Core but unknown to the provider. */
    case MissingAtProvider = 'missing_at_provider';

    /** A provider state the Core does not map. */
    case Unknown = 'unknown';
}
