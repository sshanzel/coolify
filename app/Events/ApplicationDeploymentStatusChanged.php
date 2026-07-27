<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApplicationDeploymentStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $deploymentUuid,
        public string $applicationUuid,
        public string $status,
    ) {}
}
