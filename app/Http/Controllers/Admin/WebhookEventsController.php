<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\OperatorContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Static reference documentation — every event type a webhook endpoint can
 * subscribe to, when each one fires, and its exact payload shape. Review
 * finding: an operator registering an endpoint had no way to know what to
 * expect on the other end, and — worse — a receiver subscribed to several
 * event types on the same URL had no documented, reliable way to tell
 * deliveries apart. This page and `HttpWebhookSender`'s self-describing
 * `{id, type, data}` envelope (see its own docblock) are the two halves of
 * the same fix: the wire format is now robust, and this page says so
 * explicitly, with a worked example per event.
 *
 * No project scoping — event types and their payload shapes are the same
 * platform-wide, not something that varies per Project.
 */
final class WebhookEventsController
{
    public function index(OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('webhooks:read')) {
            abort(403, 'La permission webhooks:read est requise.');
        }

        return response()->view('admin.webhook-events.index', [
            'title' => 'Types d\'événements webhook',
        ]);
    }
}
