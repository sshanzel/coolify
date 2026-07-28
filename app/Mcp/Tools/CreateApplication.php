<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\ApplicationsController;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\DelegatesToApi;
use App\Mcp\Concerns\ResolvesTeam;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateApplication extends Tool
{
    protected string $name = 'create_application';

    protected string $description = 'Create an application from a public Git repository (e.g. the repo you are working in). Never deploys — call deploy_application afterwards for a trackable deployment_uuid. Requires the write ability.';

    /**
     * Arguments forwarded verbatim to the public-application create endpoint.
     * The schema below is the MCP-side allowlist; anything else is rejected
     * by the API layer's extra-field check.
     */
    private const FORWARDED_ARGUMENTS = [
        'project_uuid', 'environment_name', 'environment_uuid', 'server_uuid', 'destination_uuid',
        'name', 'description', 'git_repository', 'git_branch', 'git_commit_sha', 'build_pack',
        'ports_exposes', 'domains', 'autogenerate_domain', 'base_directory', 'publish_directory',
        'install_command', 'build_command', 'start_command', 'dockerfile_location', 'docker_compose_location',
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

        $payload = [];
        foreach (self::FORWARDED_ARGUMENTS as $argument) {
            $value = $request->get($argument);
            if ($value !== null) {
                $payload[$argument] = $value;
            }
        }

        if (blank($payload['environment_name'] ?? null) && blank($payload['environment_uuid'] ?? null)) {
            return $this->mcpError($request, 'Either environment_name or environment_uuid is required.');
        }

        try {
            $result = $this->callApi(
                ApplicationsController::class,
                'create_public_application',
                $this->synthesizeJsonRequest('POST', '/api/v1/applications/public', $payload),
            );
        } catch (AuthorizationException) {
            return $this->mcpError($request, 'You are not authorized to create applications on this team.');
        }

        if ($result['status'] >= 400) {
            return $this->mcpError($request, $this->apiErrorMessage($result), ['api_status' => $result['status']]);
        }

        $uuid = (string) data_get($result['body'], 'uuid');

        return $this->mcpSuccess($request, $this->respond(
            [
                'uuid' => $uuid,
                'domains' => data_get($result['body'], 'domains'),
            ],
            [
                ['tool' => 'update_env_vars', 'args' => ['uuid' => $uuid], 'hint' => 'Set environment variables before deploying'],
                ['tool' => 'deploy_application', 'args' => ['uuid' => $uuid], 'hint' => 'Deploy — returns a deployment_uuid to watch'],
                ['tool' => 'get_application', 'args' => ['uuid' => $uuid], 'hint' => 'Full details'],
            ],
        ), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_uuid' => $schema->string()->description('Project UUID (see list_projects).')->required(),
            'server_uuid' => $schema->string()->description('Server UUID (see list_servers).')->required(),
            'environment_uuid' => $schema->string()->description('Environment UUID. Either this or environment_name is required.'),
            'environment_name' => $schema->string()->description('Environment name (e.g. production). Either this or environment_uuid is required.'),
            'git_repository' => $schema->string()->description('Public Git repository URL, e.g. https://github.com/owner/repo.')->required(),
            'git_branch' => $schema->string()->description('Branch to deploy.')->required(),
            'build_pack' => $schema->string()->enum(['nixpacks', 'railpack', 'static', 'dockerfile', 'dockercompose'])->description('Build pack type.')->required(),
            'ports_exposes' => $schema->string()->description('Comma-separated ports the app listens on, e.g. "3000". Required for non-compose build packs.'),
            'domains' => $schema->string()->description('Comma-separated FQDNs, e.g. https://app.example.com. Omit to autogenerate (unless autogenerate_domain is false).'),
            'autogenerate_domain' => $schema->boolean()->description('Autogenerate a domain when none is given (default true).'),
            'name' => $schema->string()->description('Application name (defaults to a generated one).'),
            'description' => $schema->string()->description('Application description.'),
            'destination_uuid' => $schema->string()->description('Destination UUID when the server has multiple destinations.'),
            'git_commit_sha' => $schema->string()->description('Pin deployments to a specific commit SHA.'),
            'base_directory' => $schema->string()->description('Directory to use as build root, e.g. /apps/web.'),
            'publish_directory' => $schema->string()->description('Directory to publish (static builds).'),
            'install_command' => $schema->string()->description('Custom install command.'),
            'build_command' => $schema->string()->description('Custom build command.'),
            'start_command' => $schema->string()->description('Custom start command.'),
            'dockerfile_location' => $schema->string()->description('Dockerfile path (dockerfile build pack), e.g. /Dockerfile.'),
            'docker_compose_location' => $schema->string()->description('Compose file path (dockercompose build pack), e.g. /docker-compose.yaml.'),
        ];
    }
}
