<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\QueuesDeployments;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class DeployApplication extends Tool
{
    protected string $name = 'deploy_application';

    protected string $description = 'Trigger a deployment for an application by UUID. Returns a deployment_uuid — poll get_deployment until it reaches finished or failed. Requires the deploy ability.';

    use BuildsResponse;
    use QueuesDeployments;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'deploy', $this->name)) {
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

        try {
            Gate::authorize('deploy', $application);
        } catch (AuthorizationException) {
            return $this->mcpError($request, 'You are not authorized to deploy this application.', ['resource_uuid' => $uuid]);
        }

        $force = (bool) $request->get('force');

        return $this->queueDeploymentResponse(
            $request,
            $application,
            forceRebuild: $force,
            auditContext: ['resource_uuid' => $uuid, 'force' => $force],
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Application UUID.')->required(),
            'force' => $schema->boolean()->description('Force rebuild without build cache (default false).'),
        ];
    }
}
