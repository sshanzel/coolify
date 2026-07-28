<?php

use App\Actions\Application\StopApplication;
use App\Actions\Database\StartDatabase;
use App\Actions\Service\RestartService;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class]);

    InstanceSettings::query()->where('id', 0)->delete();
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true]);
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function mcpWriteToolsApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => StandaloneDocker::class,
    ], $attributes));
}

test('create_application creates an app from a public git repo without deploying it', function () {
    $token = $this->user->createToken('mcp-write', ['write'])->plainTextToken;

    $response = mcpCallTool($token, 'create_application', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'server_uuid' => $this->server->uuid,
        'git_repository' => 'https://gitlab.com/coolify/test-static-app',
        'git_branch' => 'main',
        'build_pack' => 'static',
        'ports_exposes' => '80',
        'autogenerate_domain' => false,
    ]);

    $response->assertOk();
    expect($response->json('result.isError'))->not->toBeTrue();

    $body = mcpToolJson($response);
    expect($body['data']['uuid'])->not->toBeEmpty();
    expect(collect($body['_actions'])->pluck('tool')->all())
        ->toContain('deploy_application')
        ->toContain('update_env_vars');

    $application = Application::where('uuid', $body['data']['uuid'])->first();
    expect($application)->not->toBeNull();
    expect($application->git_repository)->toBe('https://gitlab.com/coolify/test-static-app');
    expect($application->environment_id)->toBe($this->environment->id);

    // create must never instant-deploy: deploys are separate for a trackable uuid
    expect(ApplicationDeploymentQueue::count())->toBe(0);
});

test('create_application resolves github.com repos to the public github source', function () {
    GithubApp::unguarded(fn () => GithubApp::create([
        'id' => 0,
        'name' => 'Public GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => true,
        'team_id' => 0,
    ]));

    $token = $this->user->createToken('mcp-write', ['write'])->plainTextToken;

    $response = mcpCallTool($token, 'create_application', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'server_uuid' => $this->server->uuid,
        'git_repository' => 'https://github.com/coollabsio/coolify-examples',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'autogenerate_domain' => false,
    ]);

    expect($response->json('result.isError'))->not->toBeTrue();
    $body = mcpToolJson($response);

    $application = Application::where('uuid', $body['data']['uuid'])->first();
    expect($application->git_repository)->toBe('coollabsio/coolify-examples');
    expect($application->source_type)->toBe(GithubApp::class);
});

test('create_application requires the write ability', function () {
    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $response = mcpCallTool($token, 'create_application', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'server_uuid' => $this->server->uuid,
        'git_repository' => 'https://gitlab.com/coolify/test-static-app',
        'git_branch' => 'main',
        'build_pack' => 'static',
        'ports_exposes' => '80',
    ]);

    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('Missing required permissions: write');
    expect(Application::count())->toBe(0);
});

test('create_application surfaces validation errors from the api layer', function () {
    $token = $this->user->createToken('mcp-write', ['write'])->plainTextToken;

    $response = mcpCallTool($token, 'create_application', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'server_uuid' => $this->server->uuid,
        'git_repository' => 'https://gitlab.com/coolify/test-static-app',
        'git_branch' => 'main',
        'build_pack' => 'not-a-build-pack',
        'ports_exposes' => '80',
    ]);

    expect($response->json('result.isError'))->toBeTrue();
    expect(strtolower($response->json('result.content.0.text')))->toContain('build pack');
    expect(Application::count())->toBe(0);
});

test('update_application patches allowlisted config fields', function () {
    $application = mcpWriteToolsApp(['build_pack' => 'nixpacks', 'ports_exposes' => '3000']);
    $token = $this->user->createToken('mcp-write', ['write'])->plainTextToken;

    $response = mcpCallTool($token, 'update_application', [
        'uuid' => $application->uuid,
        'build_pack' => 'static',
        'ports_exposes' => '80',
    ]);

    expect($response->json('result.isError'))->not->toBeTrue();
    $application->refresh();
    expect($application->build_pack)->toBe('static');
    expect($application->ports_exposes)->toBe('80');
});

test('update_application cannot touch another teams application', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->first();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $application = Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
    ]);

    $token = $this->user->createToken('mcp-write', ['write'])->plainTextToken;
    $response = mcpCallTool($token, 'update_application', [
        'uuid' => $application->uuid,
        'build_pack' => 'static',
    ]);

    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('not found');
    expect($application->refresh()->build_pack)->toBe('nixpacks');
});

test('update_env_vars upserts environment variables without echoing values', function () {
    $application = mcpWriteToolsApp();
    $token = $this->user->createToken('mcp-write', ['write'])->plainTextToken;

    $first = mcpCallTool($token, 'update_env_vars', [
        'uuid' => $application->uuid,
        'envs' => [
            ['key' => 'APP_SECRET', 'value' => 'first-value'],
            ['key' => 'NODE_ENV', 'value' => 'production'],
        ],
    ]);

    expect($first->json('result.isError'))->not->toBeTrue();
    $firstBody = mcpToolJson($first);
    expect($firstBody['data']['keys'])->toContain('APP_SECRET')->toContain('NODE_ENV');
    expect(json_encode($firstBody))->not->toContain('first-value');

    $second = mcpCallTool($token, 'update_env_vars', [
        'uuid' => $application->uuid,
        'envs' => [
            ['key' => 'APP_SECRET', 'value' => 'second-value'],
        ],
    ]);
    expect($second->json('result.isError'))->not->toBeTrue();

    // Coolify auto-creates an is_preview twin for every app env var, so scope
    // the upsert assertion to the non-preview row.
    $rows = EnvironmentVariable::where('resourceable_type', Application::class)
        ->where('resourceable_id', $application->id)
        ->where('key', 'APP_SECRET')
        ->where('is_preview', false)
        ->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->value)->toBe('second-value');
});

test('update_env_vars requires the write ability', function () {
    $application = mcpWriteToolsApp();
    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $response = mcpCallTool($token, 'update_env_vars', [
        'uuid' => $application->uuid,
        'envs' => [['key' => 'FOO', 'value' => 'bar']],
    ]);

    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('Missing required permissions: write');
});

test('control stops an application by dispatching the stop action', function () {
    Queue::fake();
    $application = mcpWriteToolsApp();
    $token = $this->user->createToken('mcp-deploy', ['deploy'])->plainTextToken;

    $response = mcpCallTool($token, 'control', [
        'resource' => 'application',
        'action' => 'stop',
        'uuid' => $application->uuid,
    ]);

    expect($response->json('result.isError'))->not->toBeTrue();
    StopApplication::assertPushed();
});

test('control starts a stopped database by dispatching the start action', function () {
    Queue::fake();
    $database = StandalonePostgresql::create([
        'name' => 'pg-mcp-test',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'status' => 'exited',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $token = $this->user->createToken('mcp-deploy', ['deploy'])->plainTextToken;

    $response = mcpCallTool($token, 'control', [
        'resource' => 'database',
        'action' => 'start',
        'uuid' => $database->uuid,
    ]);

    expect($response->json('result.isError'))->not->toBeTrue();
    StartDatabase::assertPushed();
});

test('control restarts a service by dispatching the restart action', function () {
    Queue::fake();
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $token = $this->user->createToken('mcp-deploy', ['deploy'])->plainTextToken;

    $response = mcpCallTool($token, 'control', [
        'resource' => 'service',
        'action' => 'restart',
        'uuid' => $service->uuid,
    ]);

    expect($response->json('result.isError'))->not->toBeTrue();
    RestartService::assertPushed();
});

test('control rejects unknown resources and actions', function () {
    $token = $this->user->createToken('mcp-deploy', ['deploy'])->plainTextToken;

    $badResource = mcpCallTool($token, 'control', [
        'resource' => 'volume',
        'action' => 'start',
        'uuid' => 'irrelevant',
    ]);
    expect($badResource->json('result.isError'))->toBeTrue();

    $badAction = mcpCallTool($token, 'control', [
        'resource' => 'application',
        'action' => 'explode',
        'uuid' => 'irrelevant',
    ]);
    expect($badAction->json('result.isError'))->toBeTrue();
});

test('control requires the deploy ability', function () {
    $application = mcpWriteToolsApp();
    $token = $this->user->createToken('mcp-write', ['write'])->plainTextToken;

    $response = mcpCallTool($token, 'control', [
        'resource' => 'application',
        'action' => 'stop',
        'uuid' => $application->uuid,
    ]);

    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('Missing required permissions: deploy');
});

test('member role tokens with write ability are rejected by the team middleware', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $token = $member->createToken('mcp-member-write', ['write'])->plainTextToken;
    $application = mcpWriteToolsApp();

    $response = mcpCallTool($token, 'update_env_vars', [
        'uuid' => $application->uuid,
        'envs' => [['key' => 'FOO', 'value' => 'bar']],
    ]);

    $response->assertForbidden();
    expect($response->json('message'))->toBe('Missing required team role.');
});

test('member role tokens with deploy ability are denied by the tool guard', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $token = $member->createToken('mcp-member-deploy', ['deploy'])->plainTextToken;
    $application = mcpWriteToolsApp();

    expectMcpAuditLog([
        'tool' => 'deploy_application',
        'team_id' => $this->team->id,
        'outcome' => 'denied',
        'reason' => 'member_role_restriction',
    ]);

    $response = mcpCallTool($token, 'deploy_application', ['uuid' => $application->uuid]);

    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('exceed your current role');
    expect(ApplicationDeploymentQueue::count())->toBe(0);
});

test('member role tokens can still use read tools', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $token = $member->createToken('mcp-member-read', ['read'])->plainTextToken;

    $response = mcpCallTool($token, 'list_applications');

    expect($response->json('result.isError'))->not->toBeTrue();
});
