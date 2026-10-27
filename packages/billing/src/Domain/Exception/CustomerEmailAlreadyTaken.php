<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0070 §1 — the email of a customer is unique **per project**.
 *
 * Two customers of the same project may not share an email address; two
 * customers of two different projects may (the address identifies nobody
 * outside the project that declared it).
 *
 * The uniqueness is carried by the blind index (`customers_project_email_unique`,
 * ADR-0083 §2), so it holds without a single row being decrypted.
 */
final class CustomerEmailAlreadyTaken extends RuntimeException
{
    public static function inProject(string $projectId): self
    {
        return new self("Another customer of project {$projectId} already carries this email address.");
    }
}
