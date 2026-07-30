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

## Building & shipping the fork image

The deploy image is **`docker.io/sshanzel/coolify:v4.x`** (what the compose on the target
host pulls). Your git changes don't reach a running server until you rebuild + push this
image and the host re-pulls it.

1. **Build + push** — from a machine with `buildx` + a Docker Hub login:
   ```bash
   docker login
   docker buildx build --platform linux/amd64 \
     -f docker/production/Dockerfile \
     -t docker.io/sshanzel/coolify:v4.x \
     --push .
   ```
   Behind a TLS-intercepting proxy (Zscaler), drop the corporate root CA into
   `docker/certs/*.pem` first (gitignored; injected at build) — otherwise the build fails
   on cert verification.

2. **Update the running host** — pull the new image and recreate only the `coolify`
   container (postgres/redis/realtime keep running):
   ```bash
   sudo bash -c 'cd /data/coolify/source && \
     docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.seccomp.yml pull coolify && \
     docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.seccomp.yml up -d --force-recreate coolify'
   ```
   The image runs DB migrations on start; the current fork changes add none, so it's a
   clean swap.

This ships the **image** (built from your local checkout → Docker Hub) and is independent
of where the git repo lives — moving the repo to GitLab doesn't affect the build.

---

## Planned work

- **Self-hosted GitLab integration** — a first-class GitLab source (HTTPS auth, nested
  subgroups, an SSRF allowlist for internal hosts, auto-registered deploy webhooks), to
  replace the current manual `git_repository` URL hack. Design + workstreams + phasing:
  [`docs/gitlab-integration-plan.md`](docs/gitlab-integration-plan.md).

---

## Merging upstream

```bash
git fetch upstream && git merge upstream/v4.x
```

The files most likely to sit near a fork customization are
`bootstrap/helpers/shared.php` and `docker/production/Dockerfile` — review those
hunks first if a merge ever conflicts.
