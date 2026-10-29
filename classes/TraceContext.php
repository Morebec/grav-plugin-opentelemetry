<?php

namespace Grav\Plugin\OpenTelemetry;

/**
 * W3C Trace Context (https://www.w3.org/TR/trace-context/): the traceparent header a request
 * arrives with, or a new trace when there is none.
 */
final class TraceContext
{
    private function __construct(
        public readonly string $traceId,
        public readonly ?string $parentSpanId,
        public readonly bool $sampled,
    ) {
    }

    public static function fromHeader(?string $traceparent): self
    {
        $parsed = $traceparent === null ? null : self::parse($traceparent);

        return $parsed ?? new self(self::randomId(16), null, true);
    }

    /** Returns null for a missing, malformed or invalid header (all-zero ids, version ff). */
    public static function parse(string $traceparent): ?self
    {
        if (!preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})(-.*)?$/', trim($traceparent), $m)) {
            return null;
        }
        [, $version, $traceId, $spanId, $flags] = $m;
        $rest = $m[5] ?? '';
        if ($version === 'ff' || ($version === '00' && $rest !== '')) {
            return null;
        }
        if ($traceId === str_repeat('0', 32) || $spanId === str_repeat('0', 16)) {
            return null;
        }

        return new self($traceId, $spanId, (hexdec($flags) & 1) === 1);
    }

    /** A random id of $bytes bytes, as lowercase hex (16 for a trace id, 8 for a span id). */
    public static function randomId(int $bytes): string
    {
        do {
            $id = bin2hex(random_bytes($bytes));
        } while (trim($id, '0') === '');

        return $id;
    }
}
