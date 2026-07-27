<?php

use App\Events\AssistantMessageReceived;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
        'session.driver' => 'array',
        'services.shipbot.url' => 'http://shipbot.test',
        'services.shipbot.secret' => 's3cret',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
});

function signedShipbotPost(array $payload, ?string $secret = 's3cret'): array
{
    $body = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', $body, $secret ?? '');

    return [$body, $signature];
}

it('accepts a validly signed event and broadcasts to the user channel', function () {
    Event::fake([AssistantMessageReceived::class]);
    [$body, $signature] = signedShipbotPost([
        'user_id' => 5,
        'team_id' => 1,
        'thread_id' => 't-1',
        'deployment_uuid' => 'dep-1',
        'event' => 'milestone',
        'milestone' => 'building',
        'status' => 'in_progress',
    ]);

    $this->call('POST', '/webhooks/shipbot/events', [], [], [], [
        'HTTP_X-Shipbot-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    Event::assertDispatched(AssistantMessageReceived::class, function (AssistantMessageReceived $event) {
        return $event->userId === 5
            && $event->threadId === 't-1'
            && $event->broadcastOn()[0]->name === 'private-user.5'
            && $event->broadcastWith()['milestone'] === 'building';
    });
});

it('rejects an invalid signature', function () {
    Event::fake([AssistantMessageReceived::class]);
    [$body] = signedShipbotPost(['user_id' => 5, 'thread_id' => 't-1']);

    $this->call('POST', '/webhooks/shipbot/events', [], [], [], [
        'HTTP_X-Shipbot-Signature' => 'sha256=wrong',
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertStatus(401);

    Event::assertNotDispatched(AssistantMessageReceived::class);
});

it('rejects when the secret is not configured', function () {
    config(['services.shipbot.secret' => null]);
    [$body, $signature] = signedShipbotPost(['user_id' => 5, 'thread_id' => 't-1']);

    $this->call('POST', '/webhooks/shipbot/events', [], [], [], [
        'HTTP_X-Shipbot-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertStatus(401);
});

it('rejects payloads without user or thread identity', function () {
    Event::fake([AssistantMessageReceived::class]);
    [$body, $signature] = signedShipbotPost(['event' => 'milestone']);

    $this->call('POST', '/webhooks/shipbot/events', [], [], [], [
        'HTTP_X-Shipbot-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertStatus(422);

    Event::assertNotDispatched(AssistantMessageReceived::class);
});
