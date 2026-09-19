<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\CreateOperator;
use Core\Identity\Contracts\CreateOperatorCommand;
use Core\Identity\Contracts\ListOperators;
use Core\Identity\Contracts\ListOperatorsCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\RevokeOperator;
use Core\Identity\Contracts\RevokeOperatorCommand;
use Core\Identity\OperatorRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

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
    public function __construct(
        private readonly ListOperators $listOperators,
        private readonly CreateOperator $createOperator,
        private readonly RevokeOperator $revokeOperator,
    ) {}

    public function index(Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('operators:read')) {
            abort(403, 'The operators:read permission is required.');
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $pageResult = $this->listOperators->list(new ListOperatorsCommand(
            page: $page,
            perPage: $perPage,
        ));

        return response()->view('admin.operators.index', [
            'title' => 'Operators',
            'operators' => $pageResult->items,
            'total' => $pageResult->total,
            'page' => $page,
            'perPage' => $perPage,
            'created' => session('admin.operators.created'),
        ]);
    }

    public function create(OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('operators:write')) {
            abort(403, 'The operators:write permission is required.');
        }

        return response()->view('admin.operators.create', [
            'title' => 'New operator',
            'roles' => OperatorRole::cases(),
        ]);
    }

    public function store(Request $request, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('operators:write')) {
            abort(403, 'The operators:write permission is required.');
        }

        /** @var array{email: string, name: string, role: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string'],
        ]);

        try {
            $created = $this->createOperator->create(
                new CreateOperatorCommand($data['email'], $data['name'], $data['role']),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['role' => $e->getMessage()]);
        }

        // The plaintext password is shown exactly once, on this
        // redirect — same one-shot-secret pattern as API key issuance and
        // the JSON endpoint, never persisted or logged.
        // @phpstan-ignore-next-line — session()->flash() dynamic call, same class of Laravel stub gap as Request::validate().
        session()->flash('admin.operators.created', [
            'email' => $data['email'],
            'plaintext_password' => $created->plaintextPassword,
        ]);

        return redirect()->route('admin.operators.index');
    }

    public function revoke(string $operatorId, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('operators:write')) {
            abort(403, 'The operators:write permission is required.');
        }

        Validator::make(['operatorId' => $operatorId], ['operatorId' => 'uuid'])->validate();

        try {
            $this->revokeOperator->revoke(
                new RevokeOperatorCommand($operatorId),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['operatorId' => $e->getMessage()]);
        }

        return redirect()->route('admin.operators.index')
            ->with('status', 'Operator revoked.');
    }
}
