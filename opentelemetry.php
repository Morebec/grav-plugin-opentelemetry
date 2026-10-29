<?php

namespace Grav\Plugin;

use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Plugin\OpenTelemetry\Clock;
use Grav\Plugin\OpenTelemetry\Exporter;
use Grav\Plugin\OpenTelemetry\OtlpJson;
use Grav\Plugin\OpenTelemetry\RequestTrace;
use Grav\Plugin\OpenTelemetry\Tracer;
use Grav\Plugin\OpenTelemetry\TraceContext;

require_once __DIR__ . '/autoload.php';

/**
 * Traces each request through Grav's phases and sends the spans to an OpenTelemetry collector
 * (OTLP/HTTP JSON) once the response is out. The trace continues the request's traceparent header,
 * so Grav's spans nest under the web server's.
 */
class OpenTelemetryPlugin extends Plugin
{
    public const VERSION = '0.1.0';

    // Run first and last among each event's listeners, so a phase covers every plugin's handlers.
    private const FIRST = 1_000_000;
    private const LAST = -1_000_000;

    private ?RequestTrace $trace = null;
    private bool $exported = false;

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [['onPluginsStart', self::FIRST], ['onPluginsEnd', self::LAST]],
            'onThemeInitialized' => ['onThemeInitialized', self::LAST],
            'onPagesInitialized' => ['onPagesInitialized', self::LAST],
            'onPageInitialized' => ['onPageInitialized', self::LAST],
            'onOutputGenerated' => ['onOutputGenerated', self::FIRST],
            'onOutputRendered' => ['onOutputRendered', self::LAST],
            'onShutdown' => [['onShutdownStart', self::FIRST], ['onShutdownEnd', self::LAST]],
        ];
    }

    public function __construct($name, Grav $grav, $config = null)
    {
        parent::__construct($name, $grav, $config);

        if (PHP_SAPI === 'cli') {
            return;
        }
        $context = TraceContext::fromHeader($_SERVER['HTTP_TRACEPARENT'] ?? null);
        if (!$context->sampled) {
            return;
        }
        $start = isset($_SERVER['REQUEST_TIME_FLOAT']) ? Clock::fromSeconds((float) $_SERVER['REQUEST_TIME_FLOAT']) : null;
        $clock = new Clock();
        $this->trace = new RequestTrace(new Tracer($context, $clock), $start ?? $clock->now(), [
            'http.request.method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'url.path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
        ]);

        // Grav registers its own shutdown function (the one that fires onShutdown) later in the
        // request. Registering from inside ours queues the export after it: a request that never
        // reaches onShutdownEnd (an exit, a fatal error) is still exported, after the response.
        register_shutdown_function(function (): void {
            register_shutdown_function([$this, 'export']);
        });
    }

    public function onPluginsStart(): void
    {
        if ($this->trace === null) {
            return;
        }
        if ($this->exporter() === null) {
            $this->trace = null; // no collector configured: record nothing

            return;
        }
        $this->trace->enter('plugins');
    }

    public function onPluginsEnd(): void
    {
        $this->trace?->enter('themes');
    }

    public function onThemeInitialized(): void
    {
        $this->trace?->enter('pages');
    }

    public function onPagesInitialized(): void
    {
        $this->trace?->enter('page');
    }

    public function onPageInitialized(): void
    {
        if ($this->trace === null) {
            return;
        }
        $page = $this->grav['page'] ?? null;
        if ($page) {
            if ($page->route()) {
                $this->trace->root->attributes['http.route'] = $page->route();
            }
            $this->trace->root->attributes['grav.page.template'] = $page->template();
        }
        $this->trace->enter('render');
    }

    public function onOutputGenerated(): void
    {
        $this->trace?->enter('output');
    }

    public function onOutputRendered(): void
    {
        $this->trace?->leave();
    }

    public function onShutdownStart(): void
    {
        if ($this->trace === null) {
            return;
        }
        // Grav sent the response just before onShutdown (fastcgi_finish_request or Connection: close).
        $this->trace->respond(http_response_code() ?: 200);
        $this->trace->enter('shutdown');
    }

    public function onShutdownEnd(): void
    {
        $this->export();
    }

    /** @internal Called at onShutdown, or as the request's last shutdown function. */
    public function export(): void
    {
        if ($this->trace === null || $this->exported) {
            return;
        }
        $this->exported = true;

        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            $this->trace->fail($error['message']);
        }
        if (!$this->trace->root->isEnded()) {
            $this->trace->respond(http_response_code() ?: 200);
        }

        $exporter = $this->exporter();
        if ($exporter === null) {
            return;
        }
        try {
            $payload = OtlpJson::encode(
                $this->trace->tracer->context->traceId,
                $this->resource(),
                'grav-plugin-opentelemetry',
                self::VERSION,
                $this->trace->spans(),
            );
        } catch (\JsonException $e) {
            $this->warn('could not encode the spans: ' . $e->getMessage());

            return;
        }
        $failure = $exporter->send($payload);
        if ($failure !== null) {
            $this->warn("export to {$exporter->url} failed: {$failure}");
        }
    }

    private function exporter(): ?Exporter
    {
        $config = (array) $this->config();
        $url = trim((string) ($config['endpoint'] ?? ''));
        if ($url === '') {
            $url = Exporter::urlFromEnv([
                'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT' => getenv('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'),
                'OTEL_EXPORTER_OTLP_ENDPOINT' => getenv('OTEL_EXPORTER_OTLP_ENDPOINT'),
            ]);
        }

        return $url === null || $url === '' ? null : new Exporter($url, (float) ($config['timeout'] ?? 1.0));
    }

    /** @return array<string, string> */
    private function resource(): array
    {
        $resource = [];
        // OTEL_RESOURCE_ATTRIBUTES: key1=value1,key2=value2 (values percent-encoded).
        foreach (array_filter(explode(',', (string) getenv('OTEL_RESOURCE_ATTRIBUTES'))) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (trim($key) !== '') {
                $resource[trim($key)] = rawurldecode(trim($value));
            }
        }
        $name = trim((string) (((array) $this->config())['service_name'] ?? '')) ?: (string) getenv('OTEL_SERVICE_NAME');

        return [
            'service.name' => $name !== '' ? $name : ($resource['service.name'] ?? 'grav'),
            'telemetry.sdk.name' => 'grav-plugin-opentelemetry',
            'telemetry.sdk.language' => 'php',
            'telemetry.sdk.version' => self::VERSION,
            'process.runtime.name' => 'php',
            'process.runtime.version' => PHP_VERSION,
            'grav.version' => defined('GRAV_VERSION') ? GRAV_VERSION : 'unknown',
        ] + $resource;
    }

    private function warn(string $message): void
    {
        $log = $this->grav['log'] ?? null;
        $log ? $log->warning("opentelemetry: {$message}") : error_log("opentelemetry: {$message}");
    }
}
