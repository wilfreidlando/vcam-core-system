<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

enum MoneyExceptionSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
