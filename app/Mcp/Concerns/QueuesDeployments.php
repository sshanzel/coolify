<?php

namespace App\Mcp\Concerns;

use App\Models\Application;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait QueuesDeployments
{
    /**
     * Queue an application deployment (or restart) and translate the queue
     * result into an MCP response. queue_full becomes an explicit error —
     * never a phantom deployment_uuid — and skipped returns the uuid of the
     * deployment already in flight so callers can watch that one instead.
     *
     * @param  array<string, mixed>  $auditContext
     */
    protected function queueDeploymentResponse(Request $request, Application $application, bool $forceRebuild = false, bool $restartOnly = false, array $auditContext = []): Response
    {
        $deploymentUuid = new_public_id();

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deploymentUuid,
            force_rebuild: $forceRebuild,
            is_api: true,
            restart_only: $restartOnly,
        );

        if ($result['status'] === 'queue_full') {
            return $this->mcpError($request, $result['message'], $auditContext + ['queue_status' => 'queue_full']);
        }

        if ($result['status'] === 'skipped') {
            $deploymentUuid = $result['deployment_uuid'];
        }

        return $this->mcpSuccess($request, $this->respond(
            [
                'deployment_uuid' => $deploymentUuid,
                'message' => $result['message'],
            ],
            [
                ['tool' => 'get_deployment', 'args' => ['deployment_uuid' => $deploymentUuid], 'hint' => 'Poll until status is finished or failed'],
            ],
        ), $auditContext + ['deployment_uuid' => $deploymentUuid, 'queue_status' => $result['status']]);
    }
}
