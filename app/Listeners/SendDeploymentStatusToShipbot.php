<?php

namespace App\Listeners;

use App\Events\ApplicationDeploymentStatusChanged;
use App\Jobs\ShipbotDeploymentEventJob;

class SendDeploymentStatusToShipbot
{
    public function handle(ApplicationDeploymentStatusChanged $event): void
    {
        if (blank(config('services.shipbot.url')) || blank(config('services.shipbot.secret'))) {
            return;
        }

        ShipbotDeploymentEventJob::dispatch([
            'deployment_uuid' => $event->deploymentUuid,
            'application_uuid' => $event->applicationUuid,
            'status' => $event->status,
            'occurred_at' => now()->toIso8601String(),
        ]);
    }
}
