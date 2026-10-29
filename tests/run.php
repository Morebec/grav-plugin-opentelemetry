<?php

// Plain PHP tests, no framework: php tests/run.php (exits 1 on the first failure).

use Grav\Plugin\OpenTelemetry\Clock;
use Grav\Plugin\OpenTelemetry\Exporter;
use Grav\Plugin\OpenTelemetry\OtlpJson;
use Grav\Plugin\OpenTelemetry\RequestTrace;
use Grav\Plugin\OpenTelemetry\Span;
use Grav\Plugin\OpenTelemetry\TraceContext;
use Grav\Plugin\OpenTelemetry\Tracer;

require __DIR__ . '/../autoload.php';

$failures = 0;
function check(string $name, callable $test): void
{
    global $failures;
    try {
        $test();
        echo "ok   {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL {$name}: {$e->getMessage()}\n";
    }
}
function same(mixed $expected, mixed $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(trim("{$what} expected " . var_export($expected, true) . ', got ' . var_export($actual, true)));
    }
}

check('traceparent: continues a valid header', function () {
    $c = TraceContext::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
    same('4bf92f3577b34da6a3ce929d0e0e4736', $c->traceId);
    same('00f067aa0ba902b7', $c->parentSpanId);
    same(true, $c->sampled);
});

check('traceparent: keeps the not-sampled flag', function () {
    same(false, TraceContext::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00')->sampled);
});

check('traceparent: accepts a future version with extra fields', function () {
    same('00f067aa0ba902b7', TraceContext::parse('01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-later')?->parentSpanId);
});

check('traceparent: rejects invalid headers', function () {
    foreach ([
        '',
        'garbage',
        '00-4BF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-01', // uppercase
        '00-00000000000000000000000000000000-00f067aa0ba902b7-01', // zero trace id
        '00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01', // zero span id
        'ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', // version ff
        '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-x', // version 00 has no extra fields
    ] as $header) {
        same(null, TraceContext::parse($header), $header);
    }
});

check('traceparent: starts a new sampled trace without a header', function () {
    $c = TraceContext::fromHeader(null);
    same(1, preg_match('/^[0-9a-f]{32}$/', $c->traceId));
    same(null, $c->parentSpanId);
    same(true, $c->sampled);
});

check('clock: converts seconds to nanoseconds and moves forward', function () {
    same(1_700_000_000_123_456_000, Clock::fromSeconds(1_700_000_000.123456));
    $clock = new Clock();
    $a = $clock->now();
    usleep(1000);
    $b = $clock->now();
    same(true, $b - $a >= 1_000_000, 'elapsed ns');
    same(true, abs($a - Clock::fromSeconds(microtime(true))) < 50_000_000, 'close to wall time');
});

check('request trace: phases tile the root under the incoming parent', function () {
    $context = TraceContext::fromHeader('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
    $clock = new Clock();
    $start = $clock->now();
    $trace = new RequestTrace(new Tracer($context, $clock), $start, ['url.path' => '/']);
    $trace->enter('plugins');
    $trace->enter('render');
    $trace->leave();
    $trace->respond(503);
    $trace->enter('shutdown');
    $spans = $trace->spans();

    same(['grav', 'bootstrap', 'plugins', 'render', 'shutdown'], array_map(fn (Span $s) => $s->name, $spans));
    same('00f067aa0ba902b7', $spans[0]->parentSpanId, 'root parent');
    foreach (array_slice($spans, 1) as $span) {
        same($spans[0]->spanId, $span->parentSpanId, "{$span->name} parent");
    }
    same($start, $spans[1]->startNs, 'bootstrap starts with the request');
    same($spans[1]->endNs, $spans[2]->startNs, 'no gap between phases');
    same(true, array_reduce($spans, fn ($ok, Span $s) => $ok && $s->isEnded() && $s->endNs >= $s->startNs, true), 'all ended');
    same(503, $spans[0]->attributes['http.response.status_code']);
    same(Span::STATUS_ERROR, $spans[0]->status);
});

check('otlp json: encodes ids, kinds, times and typed attributes', function () {
    $span = new Span('grav', 'b7ad6b7169203331', '00f067aa0ba902b7', 1_700_000_000_000_000_000, Span::KIND_INTERNAL, [
        's' => 'x', 'i' => 200, 'f' => 1.5, 'b' => true,
    ]);
    $span->endNs = 1_700_000_000_250_000_000;
    $span->status = Span::STATUS_ERROR;
    $json = json_decode(OtlpJson::encode('4bf92f3577b34da6a3ce929d0e0e4736', ['service.name' => 'site'], 'scope', '0.1.0', [$span]), true);

    $rs = $json['resourceSpans'][0];
    same([['key' => 'service.name', 'value' => ['stringValue' => 'site']]], $rs['resource']['attributes']);
    same(['name' => 'scope', 'version' => '0.1.0'], $rs['scopeSpans'][0]['scope']);
    $out = $rs['scopeSpans'][0]['spans'][0];
    same('4bf92f3577b34da6a3ce929d0e0e4736', $out['traceId']);
    same('b7ad6b7169203331', $out['spanId']);
    same('00f067aa0ba902b7', $out['parentSpanId']);
    same(1, $out['kind']);
    same('1700000000000000000', $out['startTimeUnixNano']);
    same('1700000000250000000', $out['endTimeUnixNano']);
    same(['code' => 2], $out['status']);
    same([
        ['key' => 's', 'value' => ['stringValue' => 'x']],
        ['key' => 'i', 'value' => ['intValue' => '200']],
        ['key' => 'f', 'value' => ['doubleValue' => 1.5]],
        ['key' => 'b', 'value' => ['boolValue' => true]],
    ], $out['attributes']);
});

check('exporter: traces URL from the standard variables', function () {
    same('http://alloy:4318/v1/traces', Exporter::urlFromEnv(['OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://alloy:4318/']));
    same('http://x/custom', Exporter::urlFromEnv(['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT' => 'http://x/custom', 'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://alloy:4318']));
    same(null, Exporter::urlFromEnv(['OTEL_EXPORTER_OTLP_ENDPOINT' => false]));
});

check('exporter: posts to a collector and reports failures', function () {
    $port = 18000 + random_int(0, 999);
    $out = tempnam(sys_get_temp_dir(), 'otlp');
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/collector.php'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        null,
        ['COLLECTOR_OUT' => $out],
    );
    try {
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }
        same(null, (new Exporter("http://127.0.0.1:{$port}/v1/traces", 2.0))->send('{"resourceSpans":[]}'));
        same('{"resourceSpans":[]}', file_get_contents($out), 'body received');
        same(true, str_starts_with((string) (new Exporter("http://127.0.0.1:{$port}/wrong", 2.0))->send('{}'), 'HTTP 404'), '404 reported');
    } finally {
        proc_terminate($server);
        proc_close($server);
        @unlink($out);
    }
    same(true, (new Exporter('http://127.0.0.1:1/v1/traces', 0.5))->send('{}') !== null, 'connection refused reported');
});

exit($failures === 0 ? 0 : 1);
