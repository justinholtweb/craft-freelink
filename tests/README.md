# FreeLink tests

## Unit (Pest, no Craft app)

```bash
docker exec -w /var/www/ports/craft-freelink ddev-plugin-testing-craft6-web vendor/bin/pest
```

Every FreeLink 5.3 unit test (links, collection, field normalising, GraphQL/MCP input, SSRF guard,
IP ranges, checker redirects with a scripted transport, index rows, migrator loop), plus the CP
control mapping, settings, the upgrade's class map and the Craft Link migrator's conversions.
`tests/bootstrap.php` gives them just enough container (validator, events, translations, config).

## Live (plugin-testing-craft6 harness)

Run from the harness with `docker exec -w /var/www/html ddev-plugin-testing-craft6-web php artisan
tinker --execute "require '/var/www/ports/craft-freelink/tests/Integration/<file>.php';"`.
Everything they create is prefixed `fl6`; `cleanup.php` removes it.

| File | Covers |
| --- | --- |
| `fixtures.php` | fields `fl6Cta` (single, every type, icons, advanced), `fl6Links` (multi, max 5), `fl6Native` (Craft Link); sections `fl6`, `fl6Secret`; targets (live/disabled/pending/expired/secret); user `fl6editor` |
| `checks.php` | every link type, validation, icons, CP control round trip, Twig, reverse lookups, link status, grandfathering, drafts, target visibility |
| `checks-gql.php` | field output, `FreeLinkInput` mutations, schema/site scope, `freelinkLinksTo` (in-memory schemas) |
| `checks-http.php` | HTTP checks against the harness and every SSRF refusal (needs `config/craft/freelink.php` allowing the harness host and `resources/views/fl6/{ok,redirect}.twig`) |
| `checks-migrate.php` | Craft Link field → FreeLink: dry run, real run with backup (run twice: once to migrate, once to verify) |
| `upgrade-sim.php` | FreeLink 5 stored state → upgrade migration → second run no-op (`$step = 'seed' / 'migrate' / 'verify'`, separate processes) |
| `mcp.py` | the five `freelink_*` tools and an `elements_get` → `elements_update` round trip over stdio (`python3 -I tests/Integration/mcp.py` on the host) |
| `cleanup.php` | removes everything above |

Jobs are run with `dispatchSync()`: the harness queue is shared, and a Craft job dispatched to a
queue name outside `trackedQueueNames` is deleted unrun by its `ShouldRun` middleware.
