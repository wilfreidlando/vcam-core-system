<?php

declare(strict_types=1);

namespace Core\Billing\Application\Port;

use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Port\CustomerRepository;

/**
 * The **listing** half of the customer repository.
 *
 * The Domain port (ADR-0070 §1) carries the lookups and the write; the
 * cursor-paginated listing of ADR-0070 §2 lives here, in the Application
 * layer, because it is a *read* concern of the use cases and not a
 * persistence detail of the aggregate.
 *
 * The page is cursor-based (ADR-0088): the cursor is opaque and the
 * listing never skips nor repeats a row, whatever the rows are written
 * while the page is read.
 */
interface CustomerDirectory extends CustomerRepository
{
    /**
     * The customers of a project, page after page.
     *
     * @param string|null $search matched literally on the external
     *                            reference and the display name — the two
     *                            fields the project knows a customer by
     * @param string|null $cursor the cursor of the page before
     * @return array{items: list<Customer>, nextCursor: ?string} the rows
     *                                                           of the page and
     *                                                           the cursor of
     *                                                           the next one
     *                                                           (`null` = no
     *                                                           more)
     */
    public function listByProject(
        string $projectId,
        ?string $search,
        ?string $cursor,
        int $limit,
    ): array;

    /**
     * ADR-0083 §2 — the live customers of a project whose phone carries this
     * blind index (the phone is not unique: several rows can match). At most
     * `$limit` rows; an erased customer never matches (its identity is gone).
     *
     * @param string $phoneBlindIndex 64 lowercase hex characters
     * @return list<Customer>
     */
    public function findByPhoneIndex(string $projectId, string $phoneBlindIndex, int $limit): array;
}
