<?php

namespace Grav\Plugin\OpenTelemetry;

/**
 * Keeps the spans of one request, all in the trace the request arrived with.
 */
final class Tracer
{
    /** @var list<Span> */
    private array $spans = [];

    public function __construct(
        public readonly TraceContext $context,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, string|int|float|bool> $attributes
     */
    public function start(string $name, ?Span $parent = null, ?int $startNs = null, int $kind = Span::KIND_INTERNAL, array $attributes = []): Span
    {
        $span = new Span(
            $name,
            TraceContext::randomId(8),
            $parent?->spanId ?? $this->context->parentSpanId,
            $startNs ?? $this->clock->now(),
            $kind,
            $attributes,
        );
        $this->spans[] = $span;

        return $span;
    }

    public function end(Span $span, ?int $endNs = null): void
    {
        if (!$span->isEnded()) {
            $span->endNs = max($span->startNs, $endNs ?? $this->clock->now());
        }
    }

    /** Ends every span still open (a request that stopped early) and returns them all. */
    public function finish(): array
    {
        $now = $this->clock->now();
        foreach ($this->spans as $span) {
            $this->end($span, $now);
        }

        return $this->spans;
    }

    public function now(): int
    {
        return $this->clock->now();
    }
}
