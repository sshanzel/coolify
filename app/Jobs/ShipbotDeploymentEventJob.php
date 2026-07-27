<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Forwards a deployment status transition to the Shipbot agent service.
 *
 * Unlike SendWebhookJob this deliberately skips SafeWebhookUrl: the target is
 * operator-configured infrastructure (often an internal hostname), not a
 * user-supplied URL.
 */
class ShipbotDeploymentEventJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public $backoff = 10;

    public int $maxExceptions = 5;

    public function __construct(public array $payload)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $url = rtrim(config('services.shipbot.url'), '/').'/events/deployment';

        Http::withHeaders(['X-Shipbot-Secret' => config('services.shipbot.secret')])
            ->acceptJson()
            ->timeout(10)
            ->post($url, $this->payload)
            ->throw();
    }
}
