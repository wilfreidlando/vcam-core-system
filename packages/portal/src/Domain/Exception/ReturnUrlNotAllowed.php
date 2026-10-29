<?php

declare(strict_types=1);

namespace Core\Portal\Domain\Exception;

use RuntimeException;

/**
 * ADR-0075 §Sécurité — the `return_url` is not on the project's list:
 * refused, never followed (no open redirect).
 */
final class ReturnUrlNotAllowed extends RuntimeException
{
    public static function for(string $url): self
    {
        return new self('The return_url is not one of the origins this project allowed for its portal.');
    }
}
