<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the deployment-assistant widget when Shipbot has news for a
 * conversation (milestone, terminal outcome, diagnosis).
 *
 * ShouldBroadcastNow (unlike every other event here): the queue is busy
 * running the deployment itself, this hop is already async from Shipbot's
 * perspective, and chat updates are latency-sensitive.
 *
 * Broadcasts on the per-USER channel — assistant conversations are private
 * to their user, so the team channel would leak them to teammates.
 */
class AssistantMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public string $threadId,
        public array $payload = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'AssistantMessageReceived';
    }

    public function broadcastWith(): array
    {
        return [
            'threadId' => $this->threadId,
            'event' => data_get($this->payload, 'event'),
            'milestone' => data_get($this->payload, 'milestone'),
            'status' => data_get($this->payload, 'status'),
            'deploymentUuid' => data_get($this->payload, 'deployment_uuid'),
        ];
    }
}
