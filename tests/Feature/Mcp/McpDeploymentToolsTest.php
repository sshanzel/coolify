<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

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

function mcpDeploymentApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => StandaloneDocker::class,
    ], $attributes));
}

function mcpSeedDeployment(Application $application, array $overrides = []): ApplicationDeploymentQueue
{
    $createdAt = $overrides['created_at'] ?? null;
    unset($overrides['created_at']);

    $deployment = ApplicationDeploymentQueue::create(array_merge([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $application->destination->server->id,
        'server_name' => $application->destination->server->name,
        'destination_id' => $application->destination->id,
        'deployment_uuid' => 'dep'.Str::random(12),
        'deployment_url' => '/deployment',
        'pull_request_id' => 0,
        'commit' => 'HEAD',
        'status' => 'queued',
    ], $overrides));

    if ($createdAt !== null) {
        $deployment->forceFill(['created_at' => $createdAt])->saveQuietly();
    }

    return $deployment;
}

test('deploy_application queues a deployment and returns the deployment uuid', function () {
    $application = mcpDeploymentApp();
    $token = $this->user->createToken('mcp-deploy', ['deploy'])->plainTextToken;

    $response = mcpCallTool($token, 'deploy_application', ['uuid' => $application->uuid]);
    $response->assertOk();
    expect($response->json('result.isError'))->not->toBeTrue();

    $body = mcpToolJson($response);
    expect($body['data']['deployment_uuid'])->not->toBeEmpty();
    expect(collect($body['_actions'])->pluck('tool')->all())->toContain('get_deployment');

    $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $body['data']['deployment_uuid'])->first();
    expect($deployment)->not->toBeNull();
    expect((bool) $deployment->is_api)->toBeTrue();
    expect((int) $deployment->application_id)->toBe($application->id);
});

test('deploy_application requires the deploy ability', function () {
    $application = mcpDeploymentApp();
    $token = $this->user->createToken('mcp-write', ['write'])->plainTextToken;

    $response = mcpCallTool($token, 'deploy_application', ['uuid' => $application->uuid]);

    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('Missing required permissions: deploy');
    expect(ApplicationDeploymentQueue::count())->toBe(0);
});

test('deploy_application cannot deploy another teams application', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->first();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $application = Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $token = $this->user->createToken('mcp-deploy', ['deploy'])->plainTextToken;
    $response = mcpCallTool($token, 'deploy_application', ['uuid' => $application->uuid]);

    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('not found');
    expect(ApplicationDeploymentQueue::count())->toBe(0);
});

test('deploy_application surfaces a full deployment queue as an error', function () {
    $application = mcpDeploymentApp();
    $this->server->settings->forceFill(['deployment_queue_limit' => 1])->saveQuietly();
    mcpSeedDeployment($application, ['commit' => 'other-commit']);

    $token = $this->user->createToken('mcp-deploy', ['deploy'])->plainTextToken;
    $response = mcpCallTool($token, 'deploy_application', ['uuid' => $application->uuid]);

    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('queue is full');
    expect(ApplicationDeploymentQueue::count())->toBe(1);
});

test('deploy_application returns the existing deployment when one is already queued for the commit', function () {
    $application = mcpDeploymentApp();
    $existing = mcpSeedDeployment($application, ['status' => 'in_progress']);

    $token = $this->user->createToken('mcp-deploy', ['deploy'])->plainTextToken;
    $response = mcpCallTool($token, 'deploy_application', ['uuid' => $application->uuid]);

    expect($response->json('result.isError'))->not->toBeTrue();
    $body = mcpToolJson($response);
    expect($body['data']['deployment_uuid'])->toBe($existing->deployment_uuid);
    expect($body['data']['message'])->toContain('already queued');
    expect(ApplicationDeploymentQueue::count())->toBe(1);
});

test('get_deployment hides logs without the read:sensitive ability', function () {
    $application = mcpDeploymentApp();
    $deployment = mcpSeedDeployment($application, [
        'logs' => json_encode([['output' => 'secret build output']]),
    ]);

    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $response = mcpCallTool($token, 'get_deployment', ['deployment_uuid' => $deployment->deployment_uuid]);

    expect($response->json('result.isError'))->not->toBeTrue();
    $body = mcpToolJson($response);
    expect($body['data'])->not->toHaveKey('logs');
    expect($body['data']['deployment_uuid'])->toBe($deployment->deployment_uuid);
    expect($body['data']['status'])->toBe('queued');
});

test('get_deployment exposes logs with the read:sensitive ability', function () {
    $application = mcpDeploymentApp();
    $deployment = mcpSeedDeployment($application, [
        'logs' => json_encode([['output' => 'secret build output']]),
    ]);

    $token = $this->user->createToken('mcp-read-sensitive', ['read', 'read:sensitive'])->plainTextToken;
    $response = mcpCallTool($token, 'get_deployment', ['deployment_uuid' => $deployment->deployment_uuid]);

    $body = mcpToolJson($response);
    expect($body['data'])->toHaveKey('logs');
    expect(json_encode($body['data']['logs']))->toContain('secret build output');
});

test('get_deployment cannot read another teams deployment', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->first();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $application = Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $deployment = mcpSeedDeployment($application);

    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $response = mcpCallTool($token, 'get_deployment', ['deployment_uuid' => $deployment->deployment_uuid]);

    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('not found');
});

test('list_deployments returns paginated summaries newest first without logs', function () {
    $application = mcpDeploymentApp();
    mcpSeedDeployment($application, ['created_at' => now()->subMinutes(10), 'status' => 'finished', 'logs' => json_encode([['output' => 'old logs']])]);
    $mid = mcpSeedDeployment($application, ['created_at' => now()->subMinutes(5), 'status' => 'failed']);
    $newest = mcpSeedDeployment($application, ['created_at' => now()->subMinute(), 'status' => 'queued']);

    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $response = mcpCallTool($token, 'list_deployments', ['uuid' => $application->uuid, 'per_page' => 2]);

    $body = mcpToolJson($response);
    expect($body['_pagination']['total'])->toBe(3);
    expect($body['data'])->toHaveCount(2);
    expect($body['data'][0]['deployment_uuid'])->toBe($newest->deployment_uuid);
    expect($body['data'][1]['deployment_uuid'])->toBe($mid->deployment_uuid);
    expect(json_encode($body))->not->toContain('old logs');
});

test('control restarts an application through the deployment queue', function () {
    $application = mcpDeploymentApp();

    $token = $this->user->createToken('mcp-deploy', ['deploy'])->plainTextToken;
    $response = mcpCallTool($token, 'control', [
        'resource' => 'application',
        'action' => 'restart',
        'uuid' => $application->uuid,
    ]);

    expect($response->json('result.isError'))->not->toBeTrue();
    $body = mcpToolJson($response);
    expect($body['data']['deployment_uuid'])->not->toBeEmpty();

    $row = ApplicationDeploymentQueue::where('deployment_uuid', $body['data']['deployment_uuid'])->first();
    expect($row)->not->toBeNull();
    expect((bool) $row->restart_only)->toBeTrue();
});

test('deploy_application audits success with deployment context', function () {
    $application = mcpDeploymentApp();
    $token = $this->user->createToken('mcp-deploy', ['deploy'])->plainTextToken;

    expectMcpAuditLog([
        'tool' => 'deploy_application',
        'team_id' => $this->team->id,
        'outcome' => 'success',
        'resource_uuid' => $application->uuid,
    ]);

    mcpCallTool($token, 'deploy_application', ['uuid' => $application->uuid])->assertOk();
});
