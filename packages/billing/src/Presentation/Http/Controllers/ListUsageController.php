<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\ListUsageJournal;
use Core\Billing\Contracts\ListUsageJournalCommand;
use Core\Billing\Contracts\UsageRecordRow;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Requests\ListUsageRequest;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Access\AccessScope;
use Core\Support\Http\ProblemDetails;
use DateTimeImmutable;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/usage?meter=&customer_ref=&created_from=&created_to=&cursor=&limit=`
 * — ADR-0088 §2, the cursor-paginated listing of a project's usage journal.
 */
final class ListUsageController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly ListUsageJournal $journal,
    ) {}

    /**
     * List the calling Project's usage journal.
     *
     * Every call counted by `POST /v1/usage`, oldest first, in a stable
     * order: reading the whole journal while new usage is recorded neither
     * repeats nor skips a line recorded before you started. Pass
     * `next_cursor` back as `cursor` to read the next page, and `null`
     * when there is no more. `created_from` is inclusive and `created_to`
     * exclusive, on the instant the line was recorded.
     *
     * Requires the `usage:read` scope.
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::USAGE_PAGE, description: 'A page of usage lines, and the cursor of the next one.')]
    public function __invoke(ListUsageRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('usage:read')) {
            return ProblemDetails::forbidden('The usage:read scope is required.');
        }

        $meter = $request->query('meter');
        $customerRef = $request->query('customer_ref');
        $cursor = $request->query('cursor');
        $from = $request->query('created_from');
        $to = $request->query('created_to');

        try {
            $page = $this->journal->list(
                new ListUsageJournalCommand(
                    projectId: $project->projectId,
                    meter: is_string($meter) && $meter !== '' ? $meter : null,
                    customerRef: is_string($customerRef) && $customerRef !== '' ? $customerRef : null,
                    createdFrom: is_string($from) && $from !== '' ? new DateTimeImmutable($from) : null,
                    createdTo: is_string($to) && $to !== '' ? new DateTimeImmutable($to) : null,
                    cursor: is_string($cursor) && $cursor !== '' ? $cursor : null,
                    limit: $request->integer('limit', 50),
                ),
                // ADR-0069 §2 — the public API plane's scope is the calling
                // project itself: the journal never crosses the projects.
                AccessScope::forProject($project->projectId),
            );
        } catch (InvalidArgumentException) {
            return ProblemDetails::make('validation-failed', 'The request payload is invalid', 422, 'The cursor is not one this listing issued.');
        }

        return new JsonResponse([
            'data' => array_map(
                fn (UsageRecordRow $row): array => $this->usageRecord($row),
                $page->items,
            ),
            'next_cursor' => $page->nextCursor,
        ]);
    }
}
