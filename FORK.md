# Fork changes — `sshanzel/coolify`

This is a fork of [Coolify](https://github.com/coollabsio/coolify) that carries a
small set of **deliberate deviations from upstream**. This file records them so
they survive upstream merges and are easy to audit.

This branch (`v4.x`) tracks `upstream/v4.x`. To pull upstream in:

```bash
git fetch upstream && git merge upstream/v4.x
```

---

## Docker Compose YAML-anchor normalization

**Why.** Coolify parses compose files with Symfony YAML, which cannot parse some
valid Docker Compose files — specifically ones that place a **YAML anchor on its
own line before a block collection** (the Apache Superset `docker-compose-non-dev.yml`
shape). `docker compose` itself parses these fine. Without this, such a compose
fails to deploy with `Reference "..." does not exist`.

**What.** A **fallback** (it does not change how any currently-working compose is
handled): when Symfony YAML can't parse the compose, normalize it with
`yq 'explode(.)'` — which expands anchors/aliases while preserving relative paths
(`./x`) and `${VARS}` — then use the result. If `yq` can't fix it either, the
**original Symfony error** is re-thrown (it's the more useful one). Composes that
already parse are returned byte-for-byte unchanged.

**Where.**

| File | Change |
|---|---|
| `bootstrap/helpers/shared.php` | `normalizeDockerComposeYaml()` — the fallback logic |
| `app/Models/Application.php` | `docker_compose_raw` setter (mutator) calls it |
| `app/Models/Service.php` | same setter — so **every** write is covered: git-load, UI paste, API, Service forms |
| `docker/production/Dockerfile` | bakes in `yq` (mikefarah) via `COPY --from=mikefarah/yq` |
| `tests/Unit/ComposeYamlNormalizationTest.php` | 12 tests, incl. a `yq`-presence canary |

**`yq` (mikefarah) is a required runtime dependency** and is installed into the
image. The tests do not skip when it's absent — a canary test fails loudly instead.

**Debugging.** Every time the fallback runs it logs a warning tagged
**`[compose-normalize]`** with the resource + the original Symfony error. To find
where it engaged, `grep compose-normalize` in the logs. If a new compose shape ever
slips through, that log line is where to start.

---

## Other fork deviations (high level)

- **Corporate-CA cert injection** — `docker/certs/*.pem` (gitignored) is folded into
  the image's trust store at build time, so the image can build behind a
  TLS-intercepting proxy. No-op when the directory is empty.
- **Shipbot (AI assistant) integration** — the in-app assistant widget is gated on
  `config('services.shipbot.url')`.

---

## Merging upstream

```bash
git fetch upstream && git merge upstream/v4.x
```

The files most likely to sit near a fork customization are
`bootstrap/helpers/shared.php` and `docker/production/Dockerfile` — review those
hunks first if a merge ever conflicts.
