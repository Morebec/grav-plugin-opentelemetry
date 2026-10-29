<?php

namespace Grav\Plugin\OpenTelemetry;

/**
 * One scheduler run (`bin/grav scheduler`) as a root span ("grav scheduler") with one child span
 * per job that ran. A run where no job was due records nothing worth exporting (hasJobs()).
 */
final class SchedulerTrace
{
    /** Job output kept as the status message of a failed job's span. */
    private const MAX_MESSAGE = 500;

    public readonly Span $root;
    /** @var array<string, Span> */
    private array $jobs = [];

    public function __construct(public readonly Tracer $tracer, int $startNs)
    {
        $this->root = $tracer->start('grav scheduler', null, $startNs);
    }

    public function jobStarted(string $id): void
    {
        $this->jobs[$id] = $this->tracer->start("job {$id}", $this->root, null, Span::KIND_INTERNAL, [
            'grav.scheduler.job' => $id,
        ]);
    }

    public function jobEnded(string $id, bool $successful, string $output): void
    {
        $span = $this->jobs[$id] ?? null;
        if ($span === null) {
            return;
        }
        if (!$successful) {
            $span->status = Span::STATUS_ERROR;
            $span->statusMessage = mb_substr(trim($output), 0, self::MAX_MESSAGE);
            $this->root->status = Span::STATUS_ERROR;
        }
        $this->tracer->end($span);
    }

    public function hasJobs(): bool
    {
        return $this->jobs !== [];
    }

    /** @return list<Span> */
    public function spans(): array
    {
        return $this->tracer->finish();
    }
}
