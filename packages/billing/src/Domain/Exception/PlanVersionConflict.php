<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0071 §2 — two rules of the catalogue that meet on the same write.
 *
 * An offer holds **one** draft at a time (`plan_versions_one_draft`) : a
 * draft is the operator's working paper, and it is **corrected** rather
 * than abandoned. A version's rank, on the other hand, is taken once and
 * for all (`plan_versions_plan_version_unique`).
 *
 * The two refusals are told apart. "This offer already has a draft to
 * correct" and "this version already exists" ask two different
 * corrections of the operator, and the console says which one it is
 * instead of saying "published and immutable" about a version that was
 * never published.
 */
final class PlanVersionConflict extends RuntimeException
{
    /**
     * The offer already holds a draft: it is corrected, not replaced.
     */
    public const DRAFT_EXISTS = 'draft_exists';

    /**
     * The rank is taken: another version already carries this number.
     */
    public const RANK_TAKEN = 'rank_taken';

    /**
     * @param string $reason `DRAFT_EXISTS` or `RANK_TAKEN` — which of the two rules refused the write
     * @param int $version the rank of the version the refusal is about
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $version,
    ) {
        parent::__construct($message);
    }

    /**
     * The offer already holds a draft: it is corrected, not replaced.
     */
    public static function becauseDraftExists(int $draftVersion): self
    {
        return new self(
            "The offer already holds draft version {$draftVersion}: correct it instead of opening another one (ADR-0071 §2).",
            self::DRAFT_EXISTS,
            $draftVersion,
        );
    }

    /**
     * The rank is taken: another version already carries this number.
     */
    public static function becauseRankTaken(int $version): self
    {
        return new self(
            "Version {$version} already exists for this offer (ADR-0071 §2).",
            self::RANK_TAKEN,
            $version,
        );
    }
}
