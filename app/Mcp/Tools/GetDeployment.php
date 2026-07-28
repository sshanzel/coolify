<?php

namespace App\Mcp\Tools;

use App\Enums\ApplicationDeploymentStatus;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetDeployment extends Tool
{
    protected string $name = 'get_deployment';

    protected string $description = 'Get the status of a deployment by deployment_uuid. Poll this to watch a deployment finish (status: queued, in_progress, finished, failed, cancelled-by-user). Logs are included only when the token has the read:sensitive ability.';

    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $uuid = $request->get('deployment_uuid');
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'deployment_uuid argument is required.');
        }

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $uuid)->first();
        $application = $deployment?->application;
        if (! $deployment || ! $application || data_get($application->team(), 'id') !== $teamId) {
            return $this->mcpError($request, "Deployment [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        // Logs need read:sensitive AND an admin/owner role — REST enforces the
        // role half in the ApiAbility middleware, which does not run on /mcp.
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if ($token && ($token->can('root') || $token->can('read:sensitive')) && $user->isAdminOfTeam($teamId)) {
            $deployment->makeVisible(['logs']);
        }

        $running = in_array($deployment->status, [
            ApplicationDeploymentStatus::QUEUED->value,
            ApplicationDeploymentStatus::IN_PROGRESS->value,
        ], true);

        $actions = $running
            ? [['tool' => 'get_deployment', 'args' => ['deployment_uuid' => $uuid], 'hint' => 'Still running — poll again']]
            : [['tool' => 'get_application', 'args' => ['uuid' => $application->uuid], 'hint' => 'Deployment settled — check the application']];

        return $this->mcpSuccess($request, $this->respond(
            $this->scrubSensitive($deployment->toArray()),
            $actions,
        ), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'deployment_uuid' => $schema->string()->description('Deployment UUID returned by deploy_application.')->required(),
        ];
    }
}
