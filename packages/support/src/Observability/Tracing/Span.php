<?php

declare(strict_types=1);

namespace Core\Support\Observability\Tracing;

/**
 * One timed operation inside a trace (ADR-0059). Mutable while open:
 * attributes and status can be set until `Tracer::end()`.
 */
final class Span
{
    /** @var array<string, scalar|null> */
    private array $attributes;

    private ?int $endUnixNano = null;

    private bool $error = false;

    private ?string $statusMessage = null;

    /**
     * @param array<string, scalar|null> $attributes
     */
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly string $name,
        public readonly SpanKind $kind,
        public readonly int $startUnixNano,
        array $attributes = [],
    ) {
        $this->attributes = $attributes;
    }

    public function setAttribute(string $key, string|int|float|bool|null $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function markError(?string $message = null): self
    {
        $this->error = true;
        $this->statusMessage = $message;

        return $this;
    }

    public function finish(int $endUnixNano): void
    {
        $this->endUnixNano ??= $endUnixNano;
    }

    public function isFinished(): bool
    {
        return $this->endUnixNano !== null;
    }

    public function endUnixNano(): int
    {
        return $this->endUnixNano ?? $this->startUnixNano;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function isError(): bool
    {
        return $this->error;
    }

    public function statusMessage(): ?string
    {
        return $this->statusMessage;
    }
}
