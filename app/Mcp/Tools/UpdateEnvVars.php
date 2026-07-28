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

class UpdateEnvVars extends Tool
{
    protected string $name = 'update_env_vars';

    protected string $description = 'Bulk create/update environment variables for an application (upsert by key). Values are never echoed back. Requires the write ability.';

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

        $envs = $request->get('envs');
        if (! is_array($envs) || $envs === []) {
            return $this->mcpError($request, 'envs argument must be a non-empty array of {key, value} objects.', ['resource_uuid' => $uuid]);
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
        if (! $application) {
            return $this->mcpError($request, "Application [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        try {
            $result = $this->callApi(
                ApplicationsController::class,
                'create_bulk_envs',
                $this->synthesizeJsonRequest('PATCH', "/api/v1/applications/{$uuid}/envs/bulk", ['data' => $envs], ['uuid' => $uuid]),
            );
        } catch (AuthorizationException) {
            return $this->mcpError($request, 'You are not authorized to manage environment variables for this application.', ['resource_uuid' => $uuid]);
        }

        if ($result['status'] >= 400) {
            return $this->mcpError($request, $this->apiErrorMessage($result), ['resource_uuid' => $uuid, 'api_status' => $result['status']]);
        }

        $keys = collect($result['body'])->pluck('key')->filter()->values()->all();

        return $this->mcpSuccess($request, $this->respond(
            ['uuid' => $uuid, 'upserted' => count($keys), 'keys' => $keys],
            [
                ['tool' => 'deploy_application', 'args' => ['uuid' => $uuid], 'hint' => 'Deploy to apply the new variables'],
            ],
        ), ['resource_uuid' => $uuid, 'env_count' => count($keys)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Application UUID.')->required(),
            'envs' => $schema->array()->items($schema->object([
                'key' => $schema->string()->description('Variable name.')->required(),
                'value' => $schema->string()->description('Variable value.'),
                'is_preview' => $schema->boolean()->description('Apply to preview deployments (default false).'),
                'is_literal' => $schema->boolean()->description('Treat value literally, skipping interpolation (default false).'),
                'is_multiline' => $schema->boolean()->description('Value spans multiple lines (default false).'),
                'is_runtime' => $schema->boolean()->description('Available at runtime (default true).'),
                'is_buildtime' => $schema->boolean()->description('Available at build time (default true).'),
                'comment' => $schema->string()->description('Optional comment.'),
            ]))->description('Variables to upsert by key.')->required(),
        ];
    }
}
