# CLAUDE.md

Grav 2 plugin (slug `opentelemetry`): a span per Grav phase under a `grav` span that continues the
request's `traceparent`, posted as OTLP/HTTP JSON after the response. README.md is the reference.

- No dependencies: no Composer packages, no curl (Grav's own vendor/ would clash). Classes in
  `classes/` (`Grav\Plugin\OpenTelemetry\`), loaded by `autoload.php`.
- `opentelemetry.php` maps Grav events to phases (`RequestTrace::enter`); keep the README's phase
  table in sync with it. On the command line it traces scheduler runs instead (`SchedulerTrace`, a span
  per job through each job's `before()`/`then()`).
- Tests: `php tests/run.php` (plain PHP, no framework), run by `.github/workflows/test-plugin.yml`.
- Release: bump `VERSION` in `opentelemetry.php`, `version` in `blueprints.yaml`, add a CHANGELOG
  entry, tag `vX.Y.Z`. Morébec's sites (morebec.com and the client sites) pin a tag.
- Commits follow Conventional Commits (`feat(plugin): span per Twig template`).
