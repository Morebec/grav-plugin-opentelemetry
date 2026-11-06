# v0.3.0
## 2026-11-06

1. [](#improved)
    * The request span is now the server span, named `<method> <route>` by the page that answered (`GET /contact`), or `<method>` alone for a 404 or a request that ends before a page resolves. It was `grav`, an internal span under the web server's. Turn off Caddy's or FrankenPHP's `tracing` in front of Grav when upgrading, or each request gets two server spans.

# v0.2.0
## 2026-10-29

1. [](#new)
    * Traces `bin/grav scheduler` runs: a `grav scheduler` span with a child span per job that ran (status error and the job's output when it fails). A run with no job due sends nothing.

# v0.1.0
## 2026-10-29

1. [](#new)
    * First release: a span per Grav phase (bootstrap, plugins, themes, pages, page, render, output, shutdown) under a `grav` span that continues the request's `traceparent`, exported as OTLP/HTTP JSON after the response is sent. No dependencies.
