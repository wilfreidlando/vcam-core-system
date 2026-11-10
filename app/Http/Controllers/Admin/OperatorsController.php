<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Mail\OperatorInvitationMail;
use Core\Identity\Contracts\InviteOperator;
use Core\Identity\Contracts\InviteOperatorCommand;
use Core\Identity\Contracts\ListOperators;
use Core\Identity\Contracts\ListOperatorsCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\OperatorEmailAlreadyTaken;
use Core\Identity\Contracts\OperatorInvitationAlreadyExists;
use Core\Identity\Contracts\OperatorSummaryView;
use Core\Identity\Contracts\RevokeOperator;
use Core\Identity\Contracts\RevokeOperatorCommand;
use Core\Identity\Contracts\UpdateOperator;
use Core\Identity\Contracts\UpdateOperatorCommand;
use Core\Identity\Domain\Exception\CannotRevokeOwnAccount;
use Core\Identity\Domain\Exception\LastPlatformAdmin;
use Core\Identity\OperatorRole;
use Core\Support\Access\AccessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Operator self-service (ADR-0050): the console web mirror of
 * `packages/admin`'s JSON operator endpoints. `operators:read`/`write` is
 * granted only to PlatformAdmin's `*` wildcard (`OperatorRole`), so every
 * action here checks the permission explicitly rather than assuming the
 * route is otherwise inaccessible — the review-finding lesson from §1.1
 * (no web controller but the dashboard checked permissions at all).
 */
final class OperatorsController
{
    /**
     * `UpdateOperatorCommand` has no single-get port to prefill against
     * (only `ListOperators`, paginated) — this caps how many rows the
     * edit form's prefill lookup scans before giving up and rendering a
     * blank form. Comfortably above any realistic operator headcount for
     * this Core.
     */
    private const PREFILL_LOOKUP_LIMIT = 200;

    public function __construct(
        private readonly ListOperators $listOperators,
        private readonly InviteOperator $inviteOperator,
        private readonly RevokeOperator $revokeOperator,
        private readonly UpdateOperator $updateOperator,
    ) {}

    public function index(Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('operators:read')) {
            abort(403, 'La permission operators:read est requise.');
        }

        return $this->renderIndex($request, $operator, null);
    }

    public function create(OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('operators:write')) {
            abort(403, 'La permission operators:write est requise.');
        }

        return response()->view('admin.operators.create', [
            'title' => 'Nouvel opérateur',
            'roles' => OperatorRole::cases(),
        ]);
    }

    public function store(Request $request, OperatorContext $operator): Response|RedirectResponse
    {
        if (! $operator->hasPermission('operators:write')) {
            abort(403, 'La permission operators:write est requise.');
        }

        /** @var array{email: string, role: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'email' => ['required', 'email'],
            // ADR-0069 §1 — an empty role means an account without a
            // platform role, living in its organizations only.
            'role' => ['present', 'nullable', 'string', 'max:64'],
        ]);

        try {
            $invitation = $this->inviteOperator->invite(
                new InviteOperatorCommand($data['email'], $data['role'], $operator->operatorId),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (OperatorEmailAlreadyTaken $e) {
            // ADR-0069 §1 — one console account per mailbox: the refusal
            // belongs to the address, not to the role.
            return back()->withInput()->withErrors(['email' => $e->getMessage()]);
        } catch (OperatorInvitationAlreadyExists $e) {
            return back()->withInput()->withErrors(['email' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['role' => $e->getMessage()]);
        }

        // The activation token is shown exactly once, in THIS response,
        // and nowhere else (ADR-0081 S-07): it is rendered directly rather
        // than flashed into the session for a redirect — a flash would
        // write the clear token to the session store, where it would
        // outlive the response that carries it. The invitee chooses their
        // own password when they activate the invitation.
        //
        // The invitation ALSO travels by email: the invited person should
        // not need the operator to hand them a token in person. The email
        // carries the token in clear — it is the one place it belongs, and
        // it is single-use. If the mail cannot leave (no SMTP configured,
        // a relay down), the invitation stands and the operator copies the
        // token from the page below.
        $token = (string) $invitation->token;
        $emailSent = $this->sendInvitation($invitation->email, $token, $invitation->expiresAt->format('d/m/Y H:i'));

        return $this->renderIndex($request, $operator, [
            'email' => $invitation->email,
            'token' => $token,
            'expires_at' => $invitation->expiresAt->format('Y-m-d H:i'),
            'email_sent' => $emailSent,
        ]);
    }

    public function revoke(string $operatorId, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('operators:write')) {
            abort(403, 'La permission operators:write est requise.');
        }

        Validator::make(['operatorId' => $operatorId], ['operatorId' => 'uuid'])->validate();

        try {
            $this->revokeOperator->revoke(
                new RevokeOperatorCommand($operatorId),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (CannotRevokeOwnAccount) {
            return back()->withErrors(['operatorId' => 'Vous ne pouvez pas révoquer votre propre compte : demandez à un autre administrateur.']);
        } catch (LastPlatformAdmin) {
            return back()->withErrors(['operatorId' => 'La console garde toujours un administrateur de plateforme actif : invitez-en un autre avant de révoquer celui-ci.']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['operatorId' => $e->getMessage()]);
        }

        return redirect()->route('admin.operators.index')
            ->with('status', 'Opérateur révoqué.');
    }

    public function edit(string $operatorId, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('operators:write')) {
            abort(403, 'La permission operators:write est requise.');
        }

        Validator::make(['operatorId' => $operatorId], ['operatorId' => 'uuid'])->validate();

        return response()->view('admin.operators.edit', [
            'title' => "Modifier l'opérateur",
            'operatorId' => $operatorId,
            'operatorRow' => $this->findOperator($operatorId, $operator->scope),
            'roles' => OperatorRole::cases(),
        ]);
    }

    public function update(string $operatorId, Request $request, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('operators:write')) {
            abort(403, 'La permission operators:write est requise.');
        }

        Validator::make(['operatorId' => $operatorId], ['operatorId' => 'uuid'])->validate();

        /** @var array{email: string, name: string, role: string|null} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            // ADR-0069 §1 — the platform role is optional: an empty role
            // means "no platform role at all" (the account keeps only its
            // organization memberships). The key must still be present, so
            // a partial update never clears the role by accident.
            'role' => ['present', 'nullable', 'string', 'max:64'],
        ]);

        try {
            $this->updateOperator->update(
                new UpdateOperatorCommand($operatorId, $data['email'], $data['name'], $data['role'] ?? ''),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (OperatorEmailAlreadyTaken $e) {
            // ADR-0069 §1 — one console account per mailbox: the refusal
            // belongs to the address, not to the role.
            return back()->withInput()->withErrors(['email' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['role' => $e->getMessage()]);
        }

        return redirect()->route('admin.operators.index')
            ->with('status', 'Opérateur mis à jour : '.$data['email']);
    }

    /**
     * Envoie l'invitation à l'invité.
     *
     * Un échec d'envoi n'annule **rien** : l'invitation existe, et
     * l'opérateur copie le jeton affiché sur la page. C'est un envoi, pas
     * une condition : la console ne doit jamais dépendre d'un relais SMTP.
     */
    private function sendInvitation(string $email, string $token, string $expiresAt): bool
    {
        try {
            Mail::to($email)->send(new OperatorInvitationMail(
                email: $email,
                token: $token,
                expiresAt: $expiresAt,
                invitationUrl: route('admin.invitation.show'),
            ));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array{email: string, token: string, expires_at: string}|null $created rendu dans CETTE réponse, jamais flashé
     */
    private function renderIndex(Request $request, OperatorContext $operator, ?array $created): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $searchRaw = (string) $request->query('search', '');

        $pageResult = $this->listOperators->list(new ListOperatorsCommand(
            page: $page,
            perPage: $perPage,
            search: $searchRaw === '' ? null : $searchRaw,
        ), $operator->scope);

        return response()->view('admin.operators.index', [
            'title' => 'Opérateurs',
            'operators' => $pageResult->items,
            'total' => $pageResult->total,
            'page' => $page,
            'perPage' => $perPage,
            'created' => $created,
            'search' => $searchRaw,
        ]);
    }

    /**
     * `UpdateOperator`'s only prefill source is `ListOperators` (no
     * single-get port exists) — this scans up to `PREFILL_LOOKUP_LIMIT`
     * rows looking for a matching id and returns `null` if it isn't
     * found there (a large enough operator roster, or one growing while
     * this page loads, means the edit form falls back to
     * `operatorId`-only prefill — see admin.operators.edit's blank-field
     * note).
     */
    private function findOperator(string $operatorId, AccessScope $scope): ?OperatorSummaryView
    {
        $page = $this->listOperators->list(new ListOperatorsCommand(
            page: 1,
            perPage: self::PREFILL_LOOKUP_LIMIT,
        ), $scope);

        foreach ($page->items as $item) {
            if ($item->id === $operatorId) {
                return $item;
            }
        }

        return null;
    }
}
