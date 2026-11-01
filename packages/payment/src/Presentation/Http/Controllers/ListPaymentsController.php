<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Payment\Contracts\ListPaymentsByCursor;
use Core\Payment\Contracts\ListPaymentsByCursorCommand;
use Core\Payment\Presentation\Http\Presenters\PaymentJson;
use Core\Payment\Presentation\Http\Requests\ListPaymentsRequest;
use Core\Payment\Presentation\Http\Responses\PaymentResponses;
use Core\Support\Access\AccessScope;
use Core\Support\Http\ProblemDetails;
use DateTimeImmutable;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/payments?status=&created_from=&created_to=&cursor=&limit=` —
 * ADR-0088 §2, the cursor-paginated listing of a project's payments.
 */
final class ListPaymentsController
{
    public function __construct(
        private readonly ListPaymentsByCursor $listPayments,
    ) {}

    /**
     * List the calling Project's payments.
     *
     * The listing is paginated by an opaque cursor: pass `next_cursor`
     * back as `cursor` to read the next page, and `null` when there is
     * no more. Payments come oldest first, in a stable order, so reading
     * the whole listing while new payments arrive neither repeats nor
     * skips a payment created before you started. `created_from` is
     * inclusive and `created_to` exclusive. Each item has the shape of
     * `GET /v1/payments/{id}`.
     *
     * Requires the `payments:read` scope.
     */
    #[ScrambleResponse(status: 200, type: PaymentResponses::PAYMENT_PAGE, description: 'A page of payments, and the cursor of the next one.')]
    public function __invoke(ListPaymentsRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('payments:read')) {
            return ProblemDetails::forbidden('The payments:read scope is required.');
        }

        $status = $request->query('status');
        $cursor = $request->query('cursor');
        $from = $request->query('created_from');
        $to = $request->query('created_to');

        try {
            $page = $this->listPayments->list(
                new ListPaymentsByCursorCommand(
                    projectId: $project->projectId,
                    status: is_string($status) && $status !== '' ? $status : null,
                    createdFrom: is_string($from) && $from !== '' ? new DateTimeImmutable($from) : null,
                    createdTo: is_string($to) && $to !== '' ? new DateTimeImmutable($to) : null,
                    cursor: is_string($cursor) && $cursor !== '' ? $cursor : null,
                    limit: $request->integer('limit', 50),
                ),
                // ADR-0069 §2 — the public API plane's scope is the calling
                // project itself: the listing never crosses the projects.
                AccessScope::forProject($project->projectId),
            );
        } catch (InvalidArgumentException) {
            return ProblemDetails::make('validation-failed', 'The request payload is invalid', 422, 'The cursor is not one this listing issued.');
        }

        return new JsonResponse([
            'data' => array_map(PaymentJson::status(...), $page->items),
            'next_cursor' => $page->nextCursor,
        ]);
    }
}
