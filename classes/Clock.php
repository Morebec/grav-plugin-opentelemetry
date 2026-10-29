<?php

namespace Grav\Plugin\OpenTelemetry;

/**
 * Wall-clock time in nanoseconds since the epoch, measured with the monotonic clock from one anchor
 * so that span durations don't depend on microtime()'s float precision.
 */
final class Clock
{
    private int $wallNs;
    private int $monoNs;

    public function __construct()
    {
        $this->monoNs = hrtime(true);
        $this->wallNs = self::fromSeconds(microtime(true));
    }

    public function now(): int
    {
        return $this->wallNs + (hrtime(true) - $this->monoNs);
    }

    /** Converts a float timestamp ($_SERVER['REQUEST_TIME_FLOAT']) to nanoseconds, to the microsecond. */
    public static function fromSeconds(float $seconds): int
    {
        return (int) round($seconds * 1_000_000) * 1000;
    }
}
