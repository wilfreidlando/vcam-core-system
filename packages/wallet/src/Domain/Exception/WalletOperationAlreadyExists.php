<?php

declare(strict_types=1);

namespace Core\Wallet\Domain\Exception;

use RuntimeException;

final class WalletOperationAlreadyExists extends RuntimeException {}
