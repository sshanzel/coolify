<?php

use App\Events\ApplicationDeploymentStatusChanged;
use App\Jobs\ShipbotDeploymentEventJob;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

it('dispatches the forwarding job when shipbot is configured', function () {
    Queue::fake([ShipbotDeploymentEventJob::class]);

    event(new ApplicationDeploymentStatusChanged('dep-1', 'app-1', 'failed'));

    Queue::assertPushed(ShipbotDeploymentEventJob::class, function (ShipbotDeploymentEventJob $job) {
        return $job->payload['deployment_uuid'] === 'dep-1'
            && $job->payload['application_uuid'] === 'app-1'
            && $job->payload['status'] === 'failed';
    });
});

it('does nothing when shipbot is not configured', function () {
    config(['services.shipbot.url' => null]);
    Queue::fake([ShipbotDeploymentEventJob::class]);

    event(new ApplicationDeploymentStatusChanged('dep-1', 'app-1', 'finished'));

    Queue::assertNotPushed(ShipbotDeploymentEventJob::class);
});

it('posts the payload to shipbot with the shared secret header', function () {
    Http::fake(['shipbot.test/*' => Http::response(['watched' => true], 202)]);

    (new ShipbotDeploymentEventJob([
        'deployment_uuid' => 'dep-1',
        'application_uuid' => 'app-1',
        'status' => 'in_progress',
        'occurred_at' => '2026-07-27T12:00:00+00:00',
    ]))->handle();

    Http::assertSent(function ($request) {
        return $request->url() === 'http://shipbot.test/events/deployment'
            && $request->hasHeader('X-Shipbot-Secret', 's3cret')
            && $request['deployment_uuid'] === 'dep-1'
            && $request['status'] === 'in_progress';
    });
});

it('throws on shipbot errors so the queue retries', function () {
    Http::fake(['shipbot.test/*' => Http::response(['detail' => 'boom'], 500)]);

    expect(fn () => (new ShipbotDeploymentEventJob(['deployment_uuid' => 'dep-1']))->handle())
        ->toThrow(RequestException::class);
});
