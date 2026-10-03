<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Observability (ADR-0059)
|--------------------------------------------------------------------------
|
| Logs: JSON on stdout (LOG_CHANNEL=structured), collected by Grafana Alloy
| into Loki. Traces: OTLP/HTTP to the collector, stored in Tempo. Metrics:
| `/metrics` scraped by Prometheus. Everything carries `correlation_id`
| and `trace_id` (Core\Support\Observability\TraceContext).
|
*/

return [
    'service_name' => env('OTEL_SERVICE_NAME', 'core-system'),

    'http_log' => [
        // One `http.request.received` and one `http.response.sent` line per
        // request, bodies redacted (secrets never logged) and truncated.
        'enabled' => (bool) env('HTTP_LOG_ENABLED', true),
        'max_body_bytes' => (int) env('HTTP_LOG_MAX_BODY_BYTES', 16_384),
        // Probes and scrapes would drown the signal.
        'except' => ['up', 'health', 'ready', 'metrics', 'docs/*', 'favicon.ico', 'build/*'],
    ],

    'tracing' => [
        // Empty endpoint = tracing disabled (spans are not even collected).
        'otlp_endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', ''),
        'export_timeout_seconds' => (float) env('OTEL_EXPORTER_OTLP_TIMEOUT', 1.0),
        // Database query spans can be numerous; keep them, but skip the
        // fast ones below this threshold.
        'db_span_min_ms' => (float) env('OTEL_DB_SPAN_MIN_MS', 0.0),
    ],

    'metrics' => [
        // `/metrics` is served only to callers presenting this bearer token,
        // or from a private network address when the token is empty.
        'token' => env('METRICS_TOKEN', ''),
    ],

    // Base URL of Grafana — the admin console links each correlation id /
    // trace id to its logs and trace ("voir la trace technique").
    'grafana_url' => env('GRAFANA_URL', ''),
];
