<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Controllers;

use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Domain\Exception\CustomerEmailAlreadyTaken;
use Core\Billing\Domain\Exception\CustomerVersionConflict;
use Core\Billing\Presentation\Http\Controllers\Concerns\SerializesBillingViews;
use Core\Billing\Presentation\Http\Requests\UpsertCustomerRequest;
use Core\Billing\Presentation\Http\Responses\BillingResponses;
use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Support\Observability\TraceContext;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `PUT /v1/customers/{externalRef}` — ADR-0070 §2.
 *
 * The project declares its customer: the reference the project knows its
 * customer by always lands on the same customer, so the route is
 * idempotent by construction. It answers `201` when this call created
 * the customer and `200` when it updated one.
 *
 * The version is the optimistic lock (ADR-0070 §1): the response carries
 * it in an `ETag` header, and the caller sends it back in `If-Match` on
 * its next write — a stale one is refused with `412 version-conflict`
 * instead of overwriting someone else's change.
 */
final class UpsertCustomerController
{
    use SerializesBillingViews;

    public function __construct(
        private readonly UpsertCustomer $upsertCustomer,
    ) {}

    /**
     * Create or update a customer of the calling Project.
     *
     * The customer is declared by the project: `externalRef` is the
     * reference it knows the customer by, and the same reference always
     * lands on the same customer — the route is idempotent by
     * construction.
     *
     * Answers `201` when the customer was created and `200` when it was
     * updated; the `ETag` header carries the new version. `If-Match`
     * carries the version the caller last read: when it no longer
     * matches, the write is refused with `412 version-conflict` rather
     * than silently overwriting the change.
     *
     * Requires the `customers:write` scope. The email and the phone are
     * returned only to a caller also holding `customers:read`
     * (ADR-0070 §Sécurité).
     *
     * @throws CustomerEmailAlreadyTaken another customer of this project already carries this email (409)
     * @throws CustomerVersionConflict the version sent in If-Match no longer matches the stored one (412)
     */
    #[ScrambleResponse(status: 200, type: BillingResponses::CUSTOMER, description: 'The customer, updated (200) or created (201).')]
    #[ScrambleResponse(status: 201, type: BillingResponses::CUSTOMER, description: 'The customer was created by this call.')]
    public function __invoke(string $externalRef, UpsertCustomerRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('customers:write')) {
            return ProblemDetails::forbidden('The customers:write scope is required.');
        }

        // ADR-0070 §2 — `If-Match` is optional: absent, the write takes
        // the customer as it stands; present, it is the version the
        // caller last read and the write is refused when it moved on.
        $ifMatch = $request->header('If-Match');
        $expectedVersion = null;
        if (is_string($ifMatch) && trim($ifMatch) !== '') {
            $raw = trim($ifMatch);
            if ($raw !== '*') {
                // RFC 7232 — the entity-tag may be quoted (`"3"`) or weak
                // (`W/"3"`); the version itself is the digits.
                $normalized = trim((string) preg_replace('/^W\//i', '', $raw), '"');
                if (! ctype_digit($normalized)) {
                    return ProblemDetails::make(
                        'invalid-if-match',
                        'The If-Match header is invalid',
                        422,
                        'If-Match must carry the version of the customer as an integer.',
                    );
                }
                $expectedVersion = (int) $normalized;
            }
        }

        /** @var array{display_name: string, email?: string|null, phone?: string|null, locale?: string|null, metadata?: array<string, mixed>} $data */
        $data = $request->validated();

        // ADR-0070 §1 — the metadata is a bounded space of technical
        // identifiers: at most 16 keys and 4 KiB, the same ceiling the
        // database enforces.
        $metadata = $data['metadata'] ?? [];
        if (count($metadata) > 16 || strlen((string) json_encode($metadata)) > 4096) {
            return ProblemDetails::make(
                'validation-failed',
                'The request payload is invalid',
                422,
                'The metadata of a customer holds at most 16 keys and 4 KiB.',
                ['errors' => ['metadata' => ['The metadata of a customer holds at most 16 keys and 4 KiB.']]],
            );
        }

        $view = $this->upsertCustomer->upsert(new UpsertCustomerCommand(
            projectId: $project->projectId,
            externalRef: $externalRef,
            displayName: $data['display_name'],
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            locale: $data['locale'] ?? 'fr',
            metadata: $metadata,
            expectedVersion: $expectedVersion,
            actor: 'project:'.$project->projectId,
            correlationId: TraceContext::correlationId(),
        ));

        // ADR-0070 §2 — `201` when this call created the customer, `200`
        // when it updated one. A customer is created at version 1 and
        // every write moves the version on, so a result still standing at
        // version 1 — with no `If-Match` saying otherwise — is a customer
        // this very call created.
        $created = $expectedVersion === null && $view->version === 1;

        $response = new JsonResponse(
            $this->customerView($view, $project->hasScope('customers:read')),
            $created ? 201 : 200,
        );

        // ADR-0070 §1 — the version is the optimistic lock: the caller
        // sends it back as `If-Match` on its next write.
        $response->headers->set('ETag', (string) $view->version);

        return $response;
    }
}
