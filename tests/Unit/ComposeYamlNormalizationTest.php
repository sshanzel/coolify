<?php

use App\Models\Application;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

uses(Tests\TestCase::class);

/**
 * Tests for normalizeDockerComposeYaml() (bootstrap/helpers/shared.php) and the
 * `docker_compose_raw` model setters (Application + Service) that call it.
 *
 * Contract:
 *  - Compose Symfony can already parse (incl. SIMPLE anchors) → returned
 *    byte-for-byte unchanged; fallback never runs; nothing logged.
 *  - Compose Symfony CANNOT parse (an anchor on its own line — the Superset
 *    shape) → normalized so it parses; services, relative paths (./x) and
 *    ${VARS} all preserved.
 *  - Unfixable → the ORIGINAL Symfony error is re-thrown.
 *  - Fallback logs a greppable [compose-normalize] warning.
 *  - BOTH the Application and Service setters run it, so git-load, the UI paste
 *    path, the API and Service writes are all covered by one hook.
 *
 * yq (mikefarah) is a REQUIRED system dependency (baked into the image), so
 * these tests do NOT skip when it is absent — the canary below fails loudly.
 */

// --- fixtures ----------------------------------------------------------------

// Symfony parses SIMPLE anchors fine — this must be left untouched.
$simpleAnchorCompose = <<<'YAML'
x-vol: &vol
  - ./data:/app/data
services:
  a:
    image: redis:7
    volumes: *vol
YAML;

// BREAKING shape: an anchor on its own line before a block collection.
$brokenAnchorCompose = <<<'YAML'
x-vol:
  &vol # anchor on its own line — Symfony YAML cannot parse this
  - ./data:/app/data
services:
  a:
    image: redis:7
    volumes: *vol
  b:
    image: postgres:15
    volumes: *vol
YAML;

// Breaking shape carrying a relative path and a ${VAR}, both must survive.
$brokenWithVarsCompose = <<<'YAML'
x-vol:
  &vol # own-line anchor
  - ./docker:/app/docker
services:
  app:
    image: myrepo/app:${TAG:-latest}
    volumes: *vol
YAML;

// The real Apache Superset docker-compose-non-dev.yml shape.
$supersetStyleCompose = <<<'YAML'
x-superset-image: &superset-image apachesuperset.docker.scarf.sh/apache/superset:${TAG:-latest}
x-superset-depends-on: &superset-depends-on
  - db
  - redis
x-superset-volumes:
  &superset-volumes # /app/pythonpath_docker will be appended to the PYTHONPATH
  - ./docker:/app/docker
  - superset_home:/app/superset_home
version: "3.7"
services:
  redis:
    image: redis:7
  db:
    image: postgres:15
  superset:
    image: *superset-image
    depends_on: *superset-depends-on
    volumes: *superset-volumes
  superset-worker:
    image: *superset-image
    depends_on: *superset-depends-on
    volumes: *superset-volumes
YAML;

// --- canary: yq is a required system dependency ------------------------------

it('has mikefarah yq available (REQUIRED system dependency — not optional)', function () {
    $found = null;
    foreach (['/usr/local/bin/yq', '/usr/bin/yq', 'yq'] as $candidate) {
        try {
            $probe = new Process([$candidate, '--version']);
            $probe->run();
        } catch (\Throwable) {
            continue;
        }
        if ($probe->isSuccessful()
            && str_contains(strtolower($probe->getOutput().$probe->getErrorOutput()), 'mikefarah')) {
            $found = trim($probe->getOutput());
            break;
        }
    }

    expect($found)->not->toBeNull(
        'mikefarah/yq must be installed — it is baked into the image and required by the compose normalizer.'
    );
});

// --- happy path (fallback must NOT run) --------------------------------------

it('returns an already-parseable compose byte-for-byte unchanged', function () {
    Log::spy();
    $yaml = "services:\n  web:\n    image: nginx:latest\n    ports:\n      - 8080:80\n";

    expect(normalizeDockerComposeYaml($yaml))->toBe($yaml);
    Log::shouldNotHaveReceived('warning');
});

it('returns empty content unchanged without invoking the fallback', function () {
    Log::spy();

    expect(normalizeDockerComposeYaml(''))->toBe('');
    Log::shouldNotHaveReceived('warning');
});

it('does NOT over-fire: a SIMPLE anchor Symfony can parse is left untouched', function () use ($simpleAnchorCompose) {
    Log::spy();
    expect(Yaml::parse($simpleAnchorCompose))->toBeArray();  // Symfony handles simple anchors

    expect(normalizeDockerComposeYaml($simpleAnchorCompose))->toBe($simpleAnchorCompose);
    Log::shouldNotHaveReceived('warning');
});

// --- fallback path -----------------------------------------------------------

it('normalizes an own-line anchor compose that Symfony cannot parse', function () use ($brokenAnchorCompose) {
    expect(fn () => Yaml::parse($brokenAnchorCompose))->toThrow(ParseException::class);

    $parsed = Yaml::parse(normalizeDockerComposeYaml($brokenAnchorCompose));

    expect(array_keys($parsed['services']))->toBe(['a', 'b']);
    expect($parsed['services']['a']['volumes'])->toBe(['./data:/app/data']);
    expect($parsed['services']['b']['volumes'])->toBe(['./data:/app/data']);
});

it('preserves relative paths and ${VARS} while exploding anchors', function () use ($brokenWithVarsCompose) {
    expect(fn () => Yaml::parse($brokenWithVarsCompose))->toThrow(ParseException::class);

    $result = normalizeDockerComposeYaml($brokenWithVarsCompose);

    expect($result)->toContain('./docker:/app/docker');
    expect($result)->toContain('${TAG:-latest}');

    $parsed = Yaml::parse($result);
    expect($parsed['services']['app']['image'])->toBe('myrepo/app:${TAG:-latest}');
    expect($parsed['services']['app']['volumes'])->toBe(['./docker:/app/docker']);
});

it('normalizes the real Superset-style compose (anchor + trailing comment, multi-service)', function () use ($supersetStyleCompose) {
    expect(fn () => Yaml::parse($supersetStyleCompose))->toThrow(ParseException::class);

    $parsed = Yaml::parse(normalizeDockerComposeYaml($supersetStyleCompose));

    expect(array_keys($parsed['services']))->toBe(['redis', 'db', 'superset', 'superset-worker']);
    expect($parsed['services']['superset']['image'])->toBe('apachesuperset.docker.scarf.sh/apache/superset:${TAG:-latest}');
    expect($parsed['services']['superset']['depends_on'])->toBe(['db', 'redis']);
    expect($parsed['services']['superset']['volumes'])->toContain('./docker:/app/docker');
});

// --- logging + failure -------------------------------------------------------

it('logs a greppable [compose-normalize] warning with context when the fallback fires', function () use ($brokenAnchorCompose) {
    Log::spy();

    normalizeDockerComposeYaml($brokenAnchorCompose, ['model' => 'service', 'service_name' => 'basira']);

    Log::shouldHaveReceived('warning')
        ->withArgs(function ($message, $context = []) {
            return is_string($message)
                && str_contains($message, '[compose-normalize]')
                && ($context['model'] ?? null) === 'service'
                && ($context['service_name'] ?? null) === 'basira'
                && array_key_exists('symfony_error', $context);
        })
        ->once();
});

it('re-throws the original Symfony ParseException when normalization cannot fix it', function () {
    Log::spy();

    // Dangling alias: no matching anchor — Symfony fails AND yq exits 1.
    $yaml = "services:\n  a:\n    image: nginx\n    volumes: *nonexistent\n";

    expect(fn () => normalizeDockerComposeYaml($yaml))->toThrow(ParseException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => is_string($message) && str_contains($message, '[compose-normalize]'));
});

// --- centralization: BOTH model setters run the normalizer -------------------

it('Application docker_compose_raw setter normalizes on assignment (covers git-load, paste, API)', function () use ($supersetStyleCompose) {
    $app = new Application;
    $app->docker_compose_raw = $supersetStyleCompose;   // goes through the mutator

    // The stored value is now Symfony-parseable — it was normalized on the way in.
    $parsed = Yaml::parse($app->docker_compose_raw);
    expect(array_keys($parsed['services']))->toContain('superset');
});

it('Service docker_compose_raw setter normalizes on assignment (covers StackForm/EditCompose/API)', function () use ($supersetStyleCompose) {
    $service = new Service;
    $service->docker_compose_raw = $supersetStyleCompose;   // goes through the mutator

    $parsed = Yaml::parse($service->docker_compose_raw);
    expect(array_keys($parsed['services']))->toContain('superset');
});

it('model setter leaves already-parseable content untouched and passes null through', function () use ($simpleAnchorCompose) {
    $app = new Application;

    $app->docker_compose_raw = $simpleAnchorCompose;
    expect($app->docker_compose_raw)->toBe($simpleAnchorCompose);

    $app->docker_compose_raw = null;   // clears must pass through unchanged
    expect($app->docker_compose_raw)->toBeNull();
});
