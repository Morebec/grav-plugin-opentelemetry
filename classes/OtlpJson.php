<?php

namespace Grav\Plugin\OpenTelemetry;

/**
 * Encodes spans as an OTLP/JSON ExportTraceServiceRequest
 * (https://opentelemetry.io/docs/specs/otlp/#json-protobuf-encoding): ids as hex, 64-bit integers as strings.
 */
final class OtlpJson
{
    /**
     * @param array<string, string|int|float|bool> $resource
     * @param list<Span> $spans
     */
    public static function encode(string $traceId, array $resource, string $scope, string $version, array $spans): string
    {
        $out = [];
        foreach ($spans as $span) {
            $item = [
                'traceId' => $traceId,
                'spanId' => $span->spanId,
                'name' => $span->name,
                'kind' => $span->kind,
                'startTimeUnixNano' => (string) $span->startNs,
                'endTimeUnixNano' => (string) ($span->endNs ?? $span->startNs),
                'attributes' => self::attributes($span->attributes),
            ];
            if ($span->parentSpanId !== null) {
                $item['parentSpanId'] = $span->parentSpanId;
            }
            if ($span->status !== Span::STATUS_UNSET) {
                $item['status'] = array_filter(['code' => $span->status, 'message' => $span->statusMessage]);
            }
            $out[] = $item;
        }

        return json_encode([
            'resourceSpans' => [[
                'resource' => ['attributes' => self::attributes($resource)],
                'scopeSpans' => [[
                    'scope' => ['name' => $scope, 'version' => $version],
                    'spans' => $out,
                ]],
            ]],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string|int|float|bool> $attributes
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    private static function attributes(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            $out[] = ['key' => (string) $key, 'value' => match (true) {
                is_bool($value) => ['boolValue' => $value],
                is_int($value) => ['intValue' => (string) $value],
                is_float($value) => ['doubleValue' => $value],
                default => ['stringValue' => (string) $value],
            }];
        }

        return $out;
    }
}
