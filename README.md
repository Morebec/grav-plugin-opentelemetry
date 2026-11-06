# Grav OpenTelemetry plugin

Traces every Grav request and sends the spans to an OpenTelemetry collector over OTLP/HTTP.
The request is the trace's server span, named `<method> <route>` by the page that answered
(OpenTelemetry's HTTP convention), so a backend's per-operation views list one entry per page:

```text
my-site  GET /contact                  this plugin: the PHP request (server span)
├─ bootstrap                           request start → plugins loaded (config, autoload, Grav services)
├─ plugins                             every plugin's onPluginsInitialized
├─ themes                              theme loaded (until onThemeInitialized)
├─ pages                               request, tasks, assets, Twig set up, page tree built (until onPagesInitialized)
├─ page                                current page resolved (until onPageInitialized)
├─ render                              content and Twig rendering (until onOutputGenerated)
├─ output                              output filters and sending the response (until onOutputRendered)
└─ shutdown                            onShutdown work, after the response is sent
```

Phases follow each other with no gaps; a request that stops early (a redirect, a fatal error) ends
its open phase there. The server span carries `http.request.method`, `url.path`, `http.route` (the page's route),
`grav.page.template` and `http.response.status_code` (status error from 500 up, or on a fatal error).

**Names stay bounded.** The span is named by the page's route, never the URL: query strings and URLs
no page answers don't add names. A request that ends before a page is resolved (a redirect) is named
`<method>` alone, and so is a 404 (Grav's error page answers every unknown URL, bots' probes included).
Backends that turn span names into metrics (Grafana Cloud's Application Observability) stay within a
series per page.

The trace continues the request's W3C `traceparent` header when one comes in (a proxy or a client that
traces), and starts a new trace otherwise. Don't also turn on Caddy's or FrankenPHP's `tracing` in
front of Grav: it would add a second server span per request, named by URL.

**No dependencies.** No Composer packages, no curl: the plugin parses `traceparent`, keeps the
spans in memory and posts them once per request as OTLP/JSON with PHP's HTTP stream wrapper, after
Grav has sent the response (`onShutdown`). A failed export is logged as a warning in Grav's log,
never shown to visitors.

## Requirements

Grav 2 (PHP 8.3 or later) and an OTLP/HTTP collector (OpenTelemetry Collector, Grafana Alloy,
Tempo, Jaeger...). JSON encoding is part of OTLP/HTTP, so any compliant receiver accepts it.

## Install

Copy this repository to `user/plugins/opentelemetry` (or add it as a Git submodule there).

## Configuration

Standard OpenTelemetry environment variables, so the same settings serve Caddy's `tracing`:

| Variable | Use |
| --- | --- |
| `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT` | Full traces URL, e.g. `http://collector:4318/v1/traces`. |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | Base URL; the plugin appends `/v1/traces`. |
| `OTEL_SERVICE_NAME` | `service.name` of the spans (default `grav`). |
| `OTEL_RESOURCE_ATTRIBUTES` | Extra resource attributes, `key=value,key2=value2`. |

`OTEL_EXPORTER_OTLP_PROTOCOL` is ignored: the plugin always sends `http/json` to the HTTP endpoint.
With no endpoint set, the plugin records nothing.

`user/config/plugins/opentelemetry.yaml` overrides them:

```yaml
enabled: true
service_name: ''   # empty: OTEL_SERVICE_NAME
endpoint: ''       # empty: the variables above
timeout: 1         # seconds to wait for the collector
```

A request whose `traceparent` says "not sampled" (flags `00`) is not recorded.

## Scheduler runs

On the command line, `bin/grav scheduler` is traced too: each run that runs at least one job sends
a new trace (no parent) with a `grav scheduler` span, from the command's start to its end, and a
child span per job that ran:

```text
my-site  grav scheduler            the whole run (status error if a job failed)
├─ job cache-purge                 grav.scheduler.job = cache-purge
└─ job my-plugin-job               status error, message = the job's output (500 characters at most)
```

The plugin wraps every registered job's `before()` and `then()` callbacks at `onSchedulerInitialized`
(after every other listener), keeping the callbacks a job already had. A run where no job is due
sends nothing. Other `bin/grav` commands are not traced.

## Development

```sh
php tests/run.php
```

Plain PHP tests, no framework: `traceparent` parsing, the span timeline, the OTLP/JSON encoding,
and an export to a stand-in collector (`tests/collector.php` under `php -S`). GitHub Actions runs
them on every push and pull request.
