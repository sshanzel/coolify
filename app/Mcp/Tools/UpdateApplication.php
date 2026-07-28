<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\ApplicationsController;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\DelegatesToApi;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UpdateApplication extends Tool
{
    protected string $name = 'update_application';

    protected string $description = 'Update an application\'s build/runtime configuration (build pack, ports, commands, directories, domains). Changes take effect on the next deploy. Requires the write ability.';

    /**
     * Arguments forwarded verbatim to the application update endpoint.
     */
    private const FORWARDED_ARGUMENTS = [
        'name', 'description', 'build_pack', 'ports_exposes', 'domains', 'git_branch', 'git_commit_sha',
        'base_directory', 'publish_directory', 'install_command', 'build_command', 'start_command',
        'dockerfile_location', 'docker_compose_location', 'watch_paths',
    ];

    use BuildsResponse;
    use DelegatesToApi;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'write', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
        if (! $application) {
            return $this->mcpError($request, "Application [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $payload = [];
        foreach (self::FORWARDED_ARGUMENTS as $argument) {
            $value = $request->get($argument);
            if ($value !== null) {
                $payload[$argument] = $value;
            }
        }

        if ($payload === []) {
            return $this->mcpError($request, 'Provide at least one field to update.', ['resource_uuid' => $uuid]);
        }

        try {
            $result = $this->callApi(
                ApplicationsController::class,
                'update_by_uuid',
                $this->synthesizeJsonRequest('PATCH', "/api/v1/applications/{$uuid}", $payload, ['uuid' => $uuid]),
            );
        } catch (AuthorizationException) {
            return $this->mcpError($request, 'You are not authorized to update this application.', ['resource_uuid' => $uuid]);
        }

        if ($result['status'] >= 400) {
            return $this->mcpError($request, $this->apiErrorMessage($result), ['resource_uuid' => $uuid, 'api_status' => $result['status']]);
        }

        return $this->mcpSuccess($request, $this->respond(
            ['uuid' => $uuid, 'updated' => array_keys($payload)],
            [
                ['tool' => 'deploy_application', 'args' => ['uuid' => $uuid], 'hint' => 'Deploy to apply the new configuration'],
                ['tool' => 'get_application', 'args' => ['uuid' => $uuid], 'hint' => 'Full details'],
            ],
        ), ['resource_uuid' => $uuid, 'updated_fields' => array_keys($payload)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Application UUID.')->required(),
            'name' => $schema->string()->description('Application name.'),
            'description' => $schema->string()->description('Application description.'),
            'build_pack' => $schema->string()->enum(['nixpacks', 'railpack', 'static', 'dockerfile', 'dockercompose'])->description('Build pack type.'),
            'ports_exposes' => $schema->string()->description('Comma-separated ports the app listens on.'),
            'domains' => $schema->string()->description('Comma-separated FQDNs.'),
            'git_branch' => $schema->string()->description('Branch to deploy.'),
            'git_commit_sha' => $schema->string()->description('Pin deployments to a specific commit SHA.'),
            'base_directory' => $schema->string()->description('Directory to use as build root.'),
            'publish_directory' => $schema->string()->description('Directory to publish (static builds).'),
            'install_command' => $schema->string()->description('Custom install command.'),
            'build_command' => $schema->string()->description('Custom build command.'),
            'start_command' => $schema->string()->description('Custom start command.'),
            'dockerfile_location' => $schema->string()->description('Dockerfile path.'),
            'docker_compose_location' => $schema->string()->description('Compose file path.'),
            'watch_paths' => $schema->string()->description('Paths that trigger auto-deploys on git pushes.'),
        ];
    }
}
