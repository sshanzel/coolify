# Plan: self-hosted GitLab integration for this fork

_Status: **Proposed** — a working plan; edit and extend it as the design firms up._
_Last updated: 2026-07-29._

This is a contributor-facing implementation plan. The goal is to let this Coolify fork
deploy from a **self-hosted GitLab** (internal network, nested subgroups, private repos,
HTTPS-only) as a first-class source — not through the manual URL hack we use today.

## Where we are today

The only working path is a **manual hack**: set an app's *Configuration → Git Source* to
`https://oauth2:<token>@host/<full/nested/path>.git`. Coolify clones a *no-source*
`git_repository` verbatim over HTTPS (see `convertGitUrl()` in `bootstrap/helpers/shared.php`
— it only rewrites HTTP→SSH when a `source` is attached). It works, but:

- the token sits in the DB (`applications.git_repository`) in plaintext,
- a personal PAT is user-tied and expiry-bound (breaks deploys when the user/token dies),
- deeply nested GitLab subgroups get truncated by the non-source flow,
- there is no automatic deploy-on-push (webhook).

## Constraints (verified, not assumed)

- **HTTPS-only.** SSH (port 22) to the GitLab host is blocked network-wide (from a
  container: 22 FAIL / 443 OPEN). Deploy keys are not an option in this environment.
- **No GitLab source exists in the fork.** `app/Livewire/Source/` ships **GitHub only**.
- **SSRF guard blocks the internal GitLab.** `app/Rules/SafeExternalUrl.php` (and
  `SafeWebhookUrl.php`) reject any URL resolving to a **private IP** — i.e. every internal
  GitLab. This currently makes the App-integration path impossible for internal hosts.
- **Nested subgroups.** Paths like `group/sub1/sub2/repo` must be preserved end-to-end;
  the current parser assumes `group/project`.

## Two problems, two tracks

1. **Authentication** — how Coolify clones (HTTPS + a credential; SSH is out).
2. **Triggering** — how a deploy fires (push webhook → deploy).

The integration below solves both.

## Interim bridge (no code — what to run until this lands)

- **Clone:** use a **GitLab Deploy Token** (read_repository, not user-tied, revocable, can be
  non-expiring) in place of a personal PAT, in the same `https://<user>:<token>@…` URL. It
  bypasses the SSRF guard because that guard only gates the *Source form*, not `git_repository`.
- **Trigger:** wire Coolify's **manual deploy webhook** into the GitLab project's
  *Settings → Webhooks* (push events on `main`). A GitLab admin must allow local-network
  webhooks for it to reach an internal Coolify.

### Temporary patch shipped (remove once WS1–WS3 land)

`ValidGitRepositoryUrl` rejects credentialed HTTPS URLs because the token trips a
shell-metachar blocklist (SSH deploy keys are unreachable here, so token-in-URL is the only
option). Gated a bypass behind **`COOLIFY_ALLOW_GIT_URL_CREDENTIALS`** (config
`constants.coolify.allow_git_url_credentials`, off by default) that skips the blocklist — safe
because the URL is `escapeshellarg()`'d downstream. It leaves the token **plaintext** in
`applications.git_repository`; the proper Git source integration (below) removes both the
blocklist workaround and the plaintext credential.

## Target design — a first-class GitLab source

Mirror the existing GitHub App source so a tenant connects GitLab once and clone-auth,
nested paths, and webhooks are handled automatically. Workstreams:

### WS1 — GitLab source model + UI
Add a `GitlabApp` source (Sources → GitLab, self-hosted): instance URL, auth, and the
plumbing `convertGitUrl()` / `Application::loadComposeFile()` already expect for a `source`.
Mirror `app/Livewire/Source/Github` and the `GithubApp` model. `convertGitUrl()` already has a
`GitlabApp::class` branch — wire it up.

### WS2 — SSRF allowlist  *(smallest change; unblocks everything else)*
Extend `app/Rules/SafeExternalUrl.php` (and `SafeWebhookUrl.php`) to accept an explicit
allowlist of trusted internal hosts / CIDRs, driven by config/env, so an internal GitLab is
permitted **without** disabling the protection globally. Until this lands, no internal source
can even be created.

### WS3 — Nested-subgroup paths
Preserve full GitLab group paths (`a/b/c/d/repo`) across listing, the clone URL, and the
webhook target. Don't assume two path segments.

### WS4 — Webhook automation
On connect / app-create, register a push webhook on the tenant repo pointing at Coolify's
deploy endpoint, with a shared secret; verify the inbound `X-Gitlab-Token`. The GitHub source
already does the equivalent — follow that shape.

### WS5 — Clone auth via source tokens
Use short-lived tokens minted from the source integration instead of a long-lived token
stored in `git_repository`.

## Networking model (must hold both directions)

- **Clone:** Coolify → GitLab over HTTPS/443 (open).
- **Webhook:** GitLab → Coolify — the direction **flips**. Coolify's URL must be reachable
  from GitLab, and GitLab's **own** "Allow requests to the local network from webhooks"
  setting must be enabled. Internal-to-internal works; a **public-cloud control plane is not
  reachable from an internal GitLab** — control plane and GitLab must share a network for
  webhooks to fire.

## Open questions (decide before/while building)

- **Auth model:** OAuth application (per instance; needs a reachable redirect URI) vs.
  group/project access tokens (simpler, no redirect, expiry-bound).
- **SSRF allowlist scope:** per-instance config vs. per-source host validation; keep it tight.
- **One GitLab instance vs many:** shared corporate GitLab vs. tenant-provided — decides
  source-per-instance vs. source-per-tenant.
- **Upstreamable?** The GitLab source is plausibly upstreamable; the internal-host allowlist
  is fork-specific. Decide what to contribute upstream vs. keep here.

## Suggested phasing

1. **Now** — deploy token + manual webhook (interim bridge, no code).
2. **P1 — WS2 (SSRF allowlist)** — smallest change; makes any internal source *possible*.
3. **P2 — WS1 + WS3 (GitLab source + nested paths)** — first-class connect + clone.
4. **P3 — WS4 + WS5 (webhook automation + source tokens)** — full auto-deploy, no stored
   long-lived credentials.

## Related

- `FORK.md` — this fork's deviations from upstream (incl. the compose-normalization precedent,
  which is the model for adding fork features cleanly + tested).
- The compose YAML-anchor normalizer (`normalizeDockerComposeYaml` in
  `bootstrap/helpers/shared.php`) is a shipped example of a fork-local fix with tests.
