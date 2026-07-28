<?php

namespace App\Mcp\Tools;

use App\Actions\Application\StopApplication;
use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\RestartService;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\QueuesDeployments;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ControlResource extends Tool
{
    protected string $name = 'control';

    protected string $description = 'Start, stop, or restart an application, database, or service by UUID. Application start/restart go through the deployment queue and return a deployment_uuid. Requires the deploy ability.';

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

        $resource = $request->get('resource');
        $action = $request->get('action');
        $uuid = $request->get('uuid');

        if (! in_array($resource, ['application', 'database', 'service'], true)) {
            return $this->mcpError($request, 'resource must be one of: application, database, service.');
        }
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            return $this->mcpError($request, 'action must be one of: start, stop, restart.');
        }
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $auditContext = ['resource' => $resource, 'action' => $action, 'resource_uuid' => $uuid];

        try {
            return match ($resource) {
                'application' => $this->controlApplication($request, $teamId, $action, $uuid, $auditContext),
                'database' => $this->controlDatabase($request, $teamId, $action, $uuid, $auditContext),
                'service' => $this->controlService($request, $teamId, $action, $uuid, $auditContext),
            };
        } catch (AuthorizationException) {
            return $this->mcpError($request, "You are not authorized to {$action} this {$resource}.", $auditContext);
        }
    }

    /**
     * @param  array<string, mixed>  $auditContext
     */
    private function controlApplication(Request $request, int $teamId, string $action, string $uuid, array $auditContext): Response
    {
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
        if (! $application) {
            return $this->mcpError($request, "Application [{$uuid}] not found.", $auditContext);
        }

        Gate::authorize('deploy', $application);

        if ($action === 'stop') {
            StopApplication::dispatch($application, false, true);

            return $this->mcpSuccess($request, $this->respond(
                ['resource' => 'application', 'action' => 'stop', 'uuid' => $uuid, 'message' => 'Application stopping request queued.'],
                [['tool' => 'get_application', 'args' => ['uuid' => $uuid], 'hint' => 'Check status']],
            ), $auditContext);
        }

        return $this->queueDeploymentResponse(
            $request,
            $application,
            restartOnly: $action === 'restart',
            auditContext: $auditContext,
        );
    }

    /**
     * @param  array<string, mixed>  $auditContext
     */
    private function controlDatabase(Request $request, int $teamId, string $action, string $uuid, array $auditContext): Response
    {
        $database = queryDatabaseByUuidWithinTeam($uuid, (string) $teamId);
        if (! $database) {
            return $this->mcpError($request, "Database [{$uuid}] not found.", $auditContext);
        }

        Gate::authorize('manage', $database);

        $status = str((string) $database->status);
        if ($action === 'start' && $status->contains('running')) {
            return $this->mcpError($request, 'Database is already running.', $auditContext);
        }
        if ($action === 'stop' && ($status->contains('stopped') || $status->contains('exited'))) {
            return $this->mcpError($request, 'Database is already stopped.', $auditContext);
        }

        match ($action) {
            'start' => StartDatabase::dispatch($database),
            'stop' => StopDatabase::dispatch($database, true),
            'restart' => RestartDatabase::dispatch($database),
        };

        return $this->mcpSuccess($request, $this->respond(
            ['resource' => 'database', 'action' => $action, 'uuid' => $uuid, 'message' => "Database {$action} request queued."],
            [['tool' => 'get_database', 'args' => ['uuid' => $uuid], 'hint' => 'Check status']],
        ), $auditContext);
    }

    /**
     * @param  array<string, mixed>  $auditContext
     */
    private function controlService(Request $request, int $teamId, string $action, string $uuid, array $auditContext): Response
    {
        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($uuid)->first();
        if (! $service) {
            return $this->mcpError($request, "Service [{$uuid}] not found.", $auditContext);
        }

        Gate::authorize($action === 'stop' ? 'stop' : 'deploy', $service);

        $status = str((string) $service->status);
        if ($action === 'start' && $status->contains('running')) {
            return $this->mcpError($request, 'Service is already running.', $auditContext);
        }
        if ($action === 'stop' && ($status->contains('stopped') || $status->contains('exited'))) {
            return $this->mcpError($request, 'Service is already stopped.', $auditContext);
        }

        match ($action) {
            'start' => StartService::dispatch($service),
            'stop' => StopService::dispatch($service, false, true),
            'restart' => RestartService::dispatch($service, false),
        };

        return $this->mcpSuccess($request, $this->respond(
            ['resource' => 'service', 'action' => $action, 'uuid' => $uuid, 'message' => "Service {$action} request queued."],
            [['tool' => 'get_service', 'args' => ['uuid' => $uuid], 'hint' => 'Check status']],
        ), $auditContext);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->enum(['application', 'database', 'service'])->description('Resource type.')->required(),
            'action' => $schema->string()->enum(['start', 'stop', 'restart'])->description('Action to perform.')->required(),
            'uuid' => $schema->string()->description('Resource UUID.')->required(),
        ];
    }
}
