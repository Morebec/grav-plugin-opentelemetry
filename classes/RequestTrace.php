<?php

namespace Grav\Plugin\OpenTelemetry;

/**
 * One request as a root span ("grav") with one child span per phase. Phases follow each other:
 * entering a phase ends the previous one, so the children tile the root without gaps.
 */
final class RequestTrace
{
    public readonly Span $root;
    private ?Span $phase = null;

    /** @param array<string, string|int|float|bool> $attributes */
    public function __construct(
        public readonly Tracer $tracer,
        int $startNs,
        array $attributes = [],
        string $firstPhase = 'bootstrap',
    ) {
        $this->root = $tracer->start('grav', null, $startNs, Span::KIND_INTERNAL, $attributes);
        $this->phase = $tracer->start($firstPhase, $this->root, $startNs);
    }

    public function enter(string $phase): void
    {
        $now = $this->tracer->now();
        if ($this->phase !== null) {
            $this->tracer->end($this->phase, $now);
        }
        $this->phase = $this->tracer->start($phase, $this->root, $now);
    }

    public function leave(): void
    {
        if ($this->phase !== null) {
            $this->tracer->end($this->phase);
            $this->phase = null;
        }
    }

    public function currentPhase(): ?string
    {
        return $this->phase?->name;
    }

    /** The response is out: ends the open phase and the root span. */
    public function respond(int $status): void
    {
        $this->leave();
        $this->root->attributes['http.response.status_code'] = $status;
        if ($status >= 500) {
            $this->root->status = Span::STATUS_ERROR;
        }
        $this->tracer->end($this->root);
    }

    public function fail(string $message): void
    {
        $this->root->status = Span::STATUS_ERROR;
        $this->root->statusMessage = $message;
    }

    /** @return list<Span> */
    public function spans(): array
    {
        return $this->tracer->finish();
    }
}
