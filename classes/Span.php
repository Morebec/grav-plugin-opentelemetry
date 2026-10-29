<?php

namespace Grav\Plugin\OpenTelemetry;

final class Span
{
    public const KIND_INTERNAL = 1;
    public const KIND_SERVER = 2;

    public const STATUS_UNSET = 0;
    public const STATUS_OK = 1;
    public const STATUS_ERROR = 2;

    public ?int $endNs = null;
    public int $status = self::STATUS_UNSET;
    public string $statusMessage = '';

    /** @param array<string, string|int|float|bool> $attributes */
    public function __construct(
        public readonly string $name,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly int $startNs,
        public readonly int $kind = self::KIND_INTERNAL,
        public array $attributes = [],
    ) {
    }

    public function isEnded(): bool
    {
        return $this->endNs !== null;
    }
}
