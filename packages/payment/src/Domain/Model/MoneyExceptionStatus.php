<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

enum MoneyExceptionStatus: string
{
    case Open = 'open';

    /** A resolution was proposed above the four-eyes threshold; a second person must approve it. */
    case AwaitingApproval = 'awaiting_approval';

    case Resolved = 'resolved';
}
