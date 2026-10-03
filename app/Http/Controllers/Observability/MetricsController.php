<?php

declare(strict_types=1);

namespace App\Http\Controllers\Observability;

use App\Http\Middleware\RecordHttpMetrics;
use Core\Events\Contracts\OutboxHealth;
use Core\Notification\Contracts\NotificationStats;
use Core\Payment\Contracts\PaymentStats;
use Core\Webhook\Contracts\WebhookDeliveryStats;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * ADR-0059 — Prometheus text exposition, scraped by Grafana Alloy. Never
 * public: requires `Authorization: Bearer <METRICS_TOKEN>`, or — when no
 * token is configured — a caller on a private network (the Docker network
 * the scraper lives on).
 */
final class MetricsController
{
    /** @var list<string> */
    private const QUEUES = ['default', 'notifications', 'payment-callbacks', 'webhooks'];

    /** @var list<string> */
    private array $lines = [];

    public function __invoke(
        Request $request,
        OutboxHealth $outbox,
        PaymentStats $payments,
        NotificationStats $notifications,
        WebhookDeliveryStats $webhooks,
    ): Response {
        if (! $this->authorized($request)) {
            return new Response('Forbidden', 403, ['Content-Type' => 'text/plain']);
        }

        $this->gauge('core_up', 'The Core answered this scrape.', [[[], 1]]);

        $this->httpMetrics();

        $snapshot = $outbox->snapshot();
        $this->gauge('core_outbox_unpublished', 'Outbox rows not yet relayed.', [[[], $snapshot->unpublishedCount]]);
        $this->gauge('core_outbox_oldest_unpublished_age_seconds', 'Age of the oldest outbox row not yet relayed.', [[[], $snapshot->oldestUnpublishedAgeSeconds]]);

        $this->statusGauge('core_payments', 'Payments by canonical status (all time).', $payments->countsByStatus());
        $this->statusGauge('core_payments_last_24h', 'Payments created in the last 24 hours, by status.', $payments->countsByStatus(86_400));
        $this->statusGauge('core_notifications', 'Notifications by status (all time).', $notifications->countsByStatus());
        $this->statusGauge('core_notifications_last_24h', 'Notifications created in the last 24 hours, by status.', $notifications->countsByStatus(86_400));
        $this->statusGauge('core_webhook_deliveries', 'Webhook deliveries by status (retained rows).', $webhooks->countsByStatus());

        $queueSizes = [];
        foreach (self::QUEUES as $queue) {
            try {
                $queueSizes[] = [['queue' => $queue], Queue::size($queue)];
            } catch (Throwable) {
                // Queue backend unreachable — the absence of the series is the signal.
            }
        }
        $this->gauge('core_queue_size', 'Jobs waiting per queue.', $queueSizes);

        return new Response(implode("\n", $this->lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4']);
    }

    private function authorized(Request $request): bool
    {
        $token = config('observability.metrics.token');
        if (is_string($token) && $token !== '') {
            $given = $request->bearerToken();

            return is_string($given) && hash_equals($token, $given);
        }

        $ip = (string) $request->ip();

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function httpMetrics(): void
    {
        try {
            $raw = Redis::connection()->command('hgetall', [RecordHttpMetrics::HASH_KEY]);
        } catch (Throwable) {
            return;
        }

        $counts = [];
        $buckets = [];
        $sums = [];
        $totals = [];

        if (! is_array($raw)) {
            return;
        }

        foreach ($raw as $field => $value) {
            if (! is_numeric($value)) {
                continue;
            }
            $parts = explode('|', (string) $field);
            $kind = $parts[0];
            if ($kind === 'count' && count($parts) === 4) {
                $counts[] = [['method' => $parts[1], 'route' => $parts[2], 'status' => $parts[3]], (float) $value];
                $key = $parts[1].'|'.$parts[2];
                $totals[$key] = ($totals[$key] ?? 0) + (float) $value;
            } elseif ($kind === 'bucket' && count($parts) === 4) {
                $buckets[] = [['method' => $parts[1], 'route' => $parts[2], 'le' => $parts[3]], (float) $value];
            } elseif ($kind === 'sum' && count($parts) === 3) {
                $sums[] = [['method' => $parts[1], 'route' => $parts[2]], (float) $value];
            }
        }

        $this->series('core_http_requests_total', 'counter', 'HTTP requests handled, by route name and status.', $counts);

        $this->lines[] = '# HELP core_http_request_duration_seconds HTTP request latency.';
        $this->lines[] = '# TYPE core_http_request_duration_seconds histogram';
        foreach ($buckets as [$labels, $value]) {
            $this->lines[] = 'core_http_request_duration_seconds_bucket'.self::labels($labels).' '.self::number($value);
        }
        foreach ($sums as [$labels, $value]) {
            $this->lines[] = 'core_http_request_duration_seconds_sum'.self::labels($labels).' '.self::number($value);
        }
        foreach ($totals as $key => $value) {
            [$method, $route] = explode('|', $key, 2);
            $this->lines[] = 'core_http_request_duration_seconds_count'.self::labels(['method' => $method, 'route' => $route]).' '.self::number($value);
        }
    }

    /**
     * @param array<string, int> $counts
     */
    private function statusGauge(string $name, string $help, array $counts): void
    {
        $samples = [];
        foreach ($counts as $status => $count) {
            $samples[] = [['status' => $status], $count];
        }

        $this->gauge($name, $help, $samples);
    }

    /**
     * @param list<array{0: array<string, string>, 1: int|float}> $samples
     */
    private function gauge(string $name, string $help, array $samples): void
    {
        $this->series($name, 'gauge', $help, $samples);
    }

    /**
     * @param list<array{0: array<string, string>, 1: int|float}> $samples
     */
    private function series(string $name, string $type, string $help, array $samples): void
    {
        $this->lines[] = "# HELP {$name} {$help}";
        $this->lines[] = "# TYPE {$name} {$type}";
        foreach ($samples as [$labels, $value]) {
            $this->lines[] = $name.self::labels($labels).' '.self::number($value);
        }
    }

    /**
     * @param array<string, string> $labels
     */
    private static function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];
        foreach ($labels as $key => $value) {
            $pairs[] = $key.'="'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value).'"';
        }

        return '{'.implode(',', $pairs).'}';
    }

    private static function number(int|float $value): string
    {
        return is_int($value) || floor($value) === $value ? (string) (int) $value : rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
