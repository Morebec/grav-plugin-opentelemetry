<?php

namespace Grav\Plugin\OpenTelemetry;

/**
 * Posts an OTLP/JSON payload to the collector's /v1/traces with PHP's own HTTP stream wrapper
 * (no curl, no HTTP client library). Never throws: a lost trace must not break a page.
 */
final class Exporter
{
    public function __construct(
        public readonly string $url,
        private readonly float $timeout,
    ) {
    }

    /**
     * The traces URL from the standard variables: OTEL_EXPORTER_OTLP_TRACES_ENDPOINT as is, or
     * OTEL_EXPORTER_OTLP_ENDPOINT plus /v1/traces. Null when neither is set.
     *
     * @param array<string, string|false> $env
     */
    public static function urlFromEnv(array $env): ?string
    {
        $traces = trim((string) ($env['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'] ?? ''));
        if ($traces !== '') {
            return $traces;
        }
        $base = trim((string) ($env['OTEL_EXPORTER_OTLP_ENDPOINT'] ?? ''));

        return $base === '' ? null : rtrim($base, '/') . '/v1/traces';
    }

    /** Returns null on success, or why the export failed. */
    public function send(string $payload): ?string
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nConnection: close\r\n",
            'content' => $payload,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ]]);

        $error = null;
        set_error_handler(static function (int $no, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });
        try {
            $response = file_get_contents($this->url, false, $context);
        } finally {
            restore_error_handler();
        }

        if ($response === false) {
            return $error ?? 'no response';
        }
        // PHP 8.4 added http_get_last_response_headers(); 8.5 deprecates $http_response_header.
        $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? null);
        $status = isset($headers[0]) && preg_match('#^HTTP/\S+ (\d{3})#', $headers[0], $m) ? (int) $m[1] : 0;

        return $status >= 200 && $status < 300 ? null : "HTTP {$status}: " . substr($response, 0, 200);
    }
}
