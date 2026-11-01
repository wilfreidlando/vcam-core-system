<?php

declare(strict_types=1);

namespace Core\Payment\Application\Support;

use Core\Payment\Contracts\MoneyExceptionView;
use Core\Payment\Domain\Model\MoneyException;
use Core\Payment\Domain\Model\MoneyExceptionResolution;
use Core\Payment\Domain\Model\MoneyExceptionStatus;
use DateTimeInterface;

final class MoneyExceptionViews
{
    public static function from(MoneyException $exception): MoneyExceptionView
    {
        return new MoneyExceptionView(
            id: $exception->id,
            projectId: $exception->projectId,
            kind: $exception->kind->value,
            kindLabel: $exception->kind->label(),
            severity: $exception->severity->value,
            amountMinor: $exception->amount,
            currency: $exception->currency,
            bookedAmountMinor: $exception->bookedAmount,
            paymentId: $exception->paymentId,
            subjectRef: $exception->subjectRef,
            summary: $exception->summary,
            details: $exception->details,
            status: $exception->status->value,
            resolution: $exception->resolution?->value,
            resolutionLabel: $exception->resolution?->label(),
            resolutionReference: $exception->resolutionReference,
            resolutionNote: $exception->resolutionNote,
            resolvedBy: $exception->resolvedBy,
            approvedBy: $exception->approvedBy,
            createdAt: $exception->createdAt->format(DateTimeInterface::ATOM),
            resolvedAt: $exception->resolvedAt?->format(DateTimeInterface::ATOM),
            allowedResolutions: $exception->status === MoneyExceptionStatus::Open
                ? array_map(static fn (MoneyExceptionResolution $r): string => $r->value, $exception->allowedResolutions())
                : [],
        );
    }
}
