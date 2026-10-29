# v0.1.0
## 2026-10-29

1. [](#new)
    * First release: a span per Grav phase (bootstrap, plugins, themes, pages, page, render, output, shutdown) under a `grav` span that continues the request's `traceparent`, exported as OTLP/HTTP JSON after the response is sent. No dependencies.
