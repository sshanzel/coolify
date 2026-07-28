<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ControlResource;
use App\Mcp\Tools\CreateApplication;
use App\Mcp\Tools\DeployApplication;
use App\Mcp\Tools\GetApplication;
use App\Mcp\Tools\GetDatabase;
use App\Mcp\Tools\GetDeployment;
use App\Mcp\Tools\GetInfrastructureOverview;
use App\Mcp\Tools\GetServer;
use App\Mcp\Tools\GetService;
use App\Mcp\Tools\ListApplications;
use App\Mcp\Tools\ListDatabases;
use App\Mcp\Tools\ListDeployments;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListServers;
use App\Mcp\Tools\ListServices;
use App\Mcp\Tools\UpdateApplication;
use App\Mcp\Tools\UpdateEnvVars;
use Laravel\Mcp\Server;

class CoolifyServer extends Server
{
    protected string $name = 'Coolify';

    protected string $version = '0.2.0';

    protected string $instructions = <<<'MD'
MCP server for Coolify, scoped to the authenticated team token.

Abilities: read tools need the `read` ability; create_application / update_application / update_env_vars need `write`; deploy_application / control need `deploy`; deployment logs need `read:sensitive`. Elevated abilities also require the token owner to be a team admin/owner.

Read tools:
1. get_infrastructure_overview — start here; single call returns all servers, projects with resource counts, and aggregates.
2. list_servers / list_projects / list_applications / list_databases / list_services — paginated summary listings (default 50 per page, cap 100).
3. get_server / get_application / get_database / get_service — full details for a single UUID.
4. list_deployments / get_deployment — deployment history and status for watching a deployment.

Deploying a Git repository end-to-end (e.g. "deploy this repo" from a coding agent — use the repo's git remote URL and current branch):
1. get_infrastructure_overview — pick a server and project (or ask the user when several fit).
2. create_application — public Git repo; never deploys by itself.
3. update_env_vars — set required variables before the first deploy.
4. deploy_application — returns a deployment_uuid.
5. Poll get_deployment until status is finished or failed (logs need `read:sensitive`). Status reflects the queue — a worker (Horizon) must be running for deployments to progress.
6. control — start/stop/restart existing applications, databases, and services.

Every response is `{ data, _actions?, _pagination? }`. `_actions` suggests the next tool + args; `_pagination.next` is the args to call again for the next page.
MD;

    protected array $tools = [
        GetInfrastructureOverview::class,
        ListServers::class,
        GetServer::class,
        ListProjects::class,
        ListApplications::class,
        GetApplication::class,
        ListDatabases::class,
        GetDatabase::class,
        ListServices::class,
        GetService::class,
        CreateApplication::class,
        UpdateApplication::class,
        UpdateEnvVars::class,
        DeployApplication::class,
        GetDeployment::class,
        ListDeployments::class,
        ControlResource::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
