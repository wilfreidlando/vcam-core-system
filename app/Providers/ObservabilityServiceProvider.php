<?php

declare(strict_types=1);

namespace App\Providers;

use Core\Support\Integration\Telemetry\OtlpHttpSpanExporter;
use Core\Support\Observability\ExchangeJournal;
use Core\Support\Observability\TraceContext;
use Core\Support\Observability\Tracing\Span;
use Core\Support\Observability\Tracing\SpanKind;
use Core\Support\Observability\Tracing\Tracer;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * ADR-0059 — composition root of the tracing instrumentation. Lives in
 * `app/` because it listens to database and queue framework events, which
 * the shared kernel must not reference (G3d); the tracer and the exporter
 * themselves live in `core/support`.
 *
 * Spans are exported after the response is sent (`terminating`), after
 * each queued job, and after each console command — never on a request's
 * critical path.
 */
final class ObservabilityServiceProvider extends ServiceProvider
{
    /** The open span of the job currently processing, if any. */
    private ?Span $jobSpan = null;

    private ?string $jobPreviousParent = null;

    public function boot(): void
    {
        $tracer = $this->app->make(Tracer::class);

        $this->instrumentQueue($tracer);

        if (! $tracer->enabled()) {
            return;
        }

        $this->instrumentDatabase($tracer);

        $this->app->terminating(fn () => $this->export($tracer));
        Event::listen(CommandFinished::class, fn () => $this->export($tracer));
    }

    private function instrumentDatabase(Tracer $tracer): void
    {
        $minimumMs = config('observability.tracing.db_span_min_ms', 0.0);
        $minimumMs = is_numeric($minimumMs) ? (float) $minimumMs : 0.0;

        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($tracer, $minimumMs): void {
            if ($query->time < $minimumMs) {
                return;
            }

            $end = Tracer::nowUnixNano();
            $span = $tracer->start('SQL', SpanKind::Client, [
                'db.system' => 'postgresql',
                'db.query.text' => mb_substr($query->sql, 0, 2_000),
                'db.duration_ms' => $query->time,
            ], startUnixNano: $end - (int) round($query->time * 1_000_000));
            $tracer->end($span, $end);
        });
    }

    private function instrumentQueue(Tracer $tracer): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($tracer): void {
            // A fresh job never inherits provider exchanges recorded by the
            // previous job this worker ran.
            $this->app->make(ExchangeJournal::class)->drain();

            $this->jobPreviousParent = TraceContext::parentSpanId();
            $this->jobSpan = $tracer->start('job '.$event->job->resolveName(), SpanKind::Consumer, [
                'messaging.system' => 'redis',
                'messaging.destination.name' => $event->job->getQueue(),
                'job.attempt' => $event->job->attempts(),
            ]);
            TraceContext::setParentSpanId($this->jobSpan->spanId);
        });

        $finish = function (?string $error) use ($tracer): void {
            if ($this->jobSpan === null) {
                return;
            }

            if ($error !== null) {
                $this->jobSpan->markError($error);
            }

            $tracer->end($this->jobSpan);
            $this->jobSpan = null;
            TraceContext::setParentSpanId($this->jobPreviousParent);
            $this->export($tracer);
        };

        Event::listen(JobProcessed::class, fn () => $finish(null));
        Event::listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $e) => $finish($e->exception->getMessage()));
        Event::listen(JobFailed::class, fn (JobFailed $e) => $finish($e->exception->getMessage()));
    }

    private function export(Tracer $tracer): void
    {
        if (! $tracer->enabled()) {
            return;
        }

        $this->app->make(OtlpHttpSpanExporter::class)->export($tracer->flush());
    }
}
