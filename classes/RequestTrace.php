<?php

namespace Grav\Plugin\OpenTelemetry;

/**
 * One request as a server span ("<method> <route>") with one child span per phase. Phases follow each
 * other: entering a phase ends the previous one, so the children tile the root without gaps.
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
        $method = (string) ($attributes['http.request.method'] ?? 'GET');
        $this->root = $tracer->start($method, null, $startNs, Span::KIND_SERVER, $attributes);
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

    /**
     * The page that answers: its route becomes http.route and names the span "<method> <route>"
     * (OpenTelemetry's HTTP convention: one name per page, never per URL).
     */
    public function route(string $route): void
    {
        $this->root->attributes['http.route'] = $route;
        $this->root->name = $this->method() . ' ' . $route;
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
        if ($status === 404) {
            // Grav answered with its error page: no route, or every probed URL would share the error page's.
            unset($this->root->attributes['http.route']);
            $this->root->name = $this->method();
        }
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

    private function method(): string
    {
        return (string) ($this->root->attributes['http.request.method'] ?? 'GET');
    }

    /** @return list<Span> */
    public function spans(): array
    {
        return $this->tracer->finish();
    }
}
