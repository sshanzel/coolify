<?php

use App\Livewire\DeploymentAssistant;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    config([
        'services.shipbot.url' => 'http://shipbot.test',
        'services.shipbot.secret' => 's3cret',
    ]);
});

function fakeShipbot(array $extra = []): void
{
    Http::fake([
        'shipbot.test/threads*' => Http::response(['threads' => []]),
        ...$extra,
    ]);
}

it('sends a message to shipbot and renders the reply', function () {
    fakeShipbot([
        'shipbot.test/chat' => Http::response([
            'thread_id' => 't-1',
            'messages' => [
                ['role' => 'user', 'content' => 'deploy https://github.com/a/b'],
                ['role' => 'assistant', 'content' => 'Which **server** should I use?'],
            ],
            'pending_proposal' => null,
        ]),
    ]);

    Livewire::test(DeploymentAssistant::class)
        ->set('prompt', 'deploy https://github.com/a/b')
        ->call('send')
        ->assertSet('threadId', 't-1')
        ->assertSet('prompt', '')
        ->assertSee('server');

    Http::assertSent(function ($request) {
        return $request->url() === 'http://shipbot.test/chat'
            && $request->hasHeader('X-Shipbot-Secret', 's3cret')
            && $request['team_id'] === test()->team->id
            && $request['user_id'] === test()->user->id
            && $request['message'] === 'deploy https://github.com/a/b';
    });
});

it('shows the proposal card when shipbot pauses for confirmation', function () {
    fakeShipbot([
        'shipbot.test/chat' => Http::response([
            'thread_id' => 't-1',
            'messages' => [['role' => 'user', 'content' => 'deploy it']],
            'pending_proposal' => [
                'kind' => 'deployment_proposal',
                'repo_url' => 'https://github.com/a/b',
                'branch' => 'main',
                'server_name' => 'hetzner-1',
                'project_name' => 'sandbox',
                'environment_name' => 'production',
                'build_pack' => 'nixpacks',
                'port' => 3000,
            ],
        ]),
    ]);

    Livewire::test(DeploymentAssistant::class)
        ->set('prompt', 'deploy it')
        ->call('send')
        ->assertSee('Deployment proposal')
        ->assertSee('hetzner-1')
        ->assertSee('Confirm')
        ->assertDontSee('Compose file')
        ->assertDontSee('Base directory');
});

it('renders file locations on the proposal card when shipbot sends them', function () {
    fakeShipbot();

    Livewire::test(DeploymentAssistant::class)
        ->set('pendingProposal', [
            'kind' => 'deployment_proposal',
            'repo_url' => 'https://github.com/a/b',
            'branch' => 'main',
            'server_name' => 'hetzner-1',
            'project_name' => 'sandbox',
            'environment_name' => 'production',
            'build_pack' => 'dockercompose',
            'port' => 3000,
            'docker_compose_location' => '/docker-compose.yml',
            'base_directory' => '/services/api',
        ])
        ->assertSee('Compose file')
        ->assertSee('/docker-compose.yml')
        ->assertSee('Base directory')
        ->assertSee('/services/api')
        ->assertDontSee('Dockerfile');
});

it('renders a generic card for non-deployment proposals', function () {
    fakeShipbot();

    Livewire::test(DeploymentAssistant::class)
        ->set('pendingProposal', [
            'kind' => 'config_fix_proposal',
            'title' => 'Update deployment configuration',
            'reason' => 'compose file is docker-compose.yml',
            'summary' => [['label' => 'docker compose location', 'value' => '/docker-compose.yml']],
        ])
        ->assertSee('Update deployment configuration')
        ->assertSee('docker compose location')
        ->assertSee('/docker-compose.yml');
});

it('shows the progress indicator and poll fallback while watching', function () {
    fakeShipbot();

    Livewire::test(DeploymentAssistant::class)
        ->set('watching', true)
        ->set('watch', ['deployment_uuid' => 'dep-1', 'milestone' => 'building', 'active' => true])
        ->assertSeeHtml('wire:poll.5000ms="refreshThread"')
        ->assertSee('Deployment in progress')
        ->assertSee('building');
});

it('starts watching when the confirm response carries an active watch', function () {
    fakeShipbot([
        'shipbot.test/chat/confirm' => Http::response([
            'thread_id' => 't-1',
            'messages' => [['role' => 'assistant', 'content' => 'Deployment queued.']],
            'pending_proposal' => null,
            'watch' => ['deployment_uuid' => 'dep-1', 'milestone' => 'queued', 'active' => true],
        ]),
    ]);

    Livewire::test(DeploymentAssistant::class)
        ->set('threadId', 't-1')
        ->set('pendingProposal', ['kind' => 'deployment_proposal'])
        ->call('confirmProposal')
        ->assertSet('watching', true)
        ->assertSet('watch.milestone', 'queued');
});

it('refreshes the thread when an assistant event matches', function () {
    Http::fake([
        'shipbot.test/threads/t-1*' => Http::response([
            'thread_id' => 't-1',
            'messages' => [['role' => 'assistant', 'content' => 'Deployment finished successfully.']],
            'pending_proposal' => null,
            'watch' => ['deployment_uuid' => 'dep-1', 'milestone' => 'finished', 'active' => false],
        ]),
        'shipbot.test/threads*' => Http::response(['threads' => []]),
    ]);

    Livewire::test(DeploymentAssistant::class)
        ->set('threadId', 't-1')
        ->set('prompt', 'my unsent draft')
        ->set('watching', true)
        ->call('handleAssistantEvent', ['threadId' => 't-1', 'event' => 'terminal'])
        ->assertSee('Deployment finished successfully.')
        ->assertSet('watching', false)
        ->assertSet('prompt', 'my unsent draft');
});

it('ignores assistant events for other threads', function () {
    fakeShipbot();

    Livewire::test(DeploymentAssistant::class)
        ->set('threadId', 't-1')
        ->call('handleAssistantEvent', ['threadId' => 't-other'])
        ->assertSet('messages', []);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/threads/t-1'));
});

it('confirms a proposal and reports success', function () {
    fakeShipbot([
        'shipbot.test/chat/confirm' => Http::response([
            'thread_id' => 't-1',
            'messages' => [['role' => 'assistant', 'content' => 'Deployment queued.']],
            'pending_proposal' => null,
        ]),
    ]);

    Livewire::test(DeploymentAssistant::class)
        ->set('threadId', 't-1')
        ->set('pendingProposal', ['kind' => 'deployment_proposal', 'repo_url' => 'https://github.com/a/b'])
        ->call('confirmProposal')
        ->assertSet('pendingProposal', null)
        ->assertDispatched('success')
        ->assertSee('Deployment queued.');

    Http::assertSent(fn ($request) => $request->url() === 'http://shipbot.test/chat/confirm' && $request['approved'] === true);
});

it('cancels a proposal', function () {
    fakeShipbot([
        'shipbot.test/chat/confirm' => Http::response([
            'thread_id' => 't-1',
            'messages' => [['role' => 'assistant', 'content' => 'Okay, cancelled.']],
            'pending_proposal' => null,
        ]),
    ]);

    Livewire::test(DeploymentAssistant::class)
        ->set('threadId', 't-1')
        ->set('pendingProposal', ['kind' => 'deployment_proposal'])
        ->call('cancelProposal')
        ->assertSet('pendingProposal', null)
        ->assertNotDispatched('success');

    Http::assertSent(fn ($request) => $request->url() === 'http://shipbot.test/chat/confirm' && $request['approved'] === false);
});

it('renders the selection card with its options', function () {
    fakeShipbot();

    Livewire::test(DeploymentAssistant::class)
        ->set('pendingProposal', [
            'kind' => 'selection_proposal',
            'title' => 'Choose a server',
            'reason' => 'Several servers can host this app.',
            'options' => [
                ['id' => 'srv-1', 'label' => 'hetzner-prod', 'detail' => '2 projects'],
                ['id' => 'srv-2', 'label' => 'staging-box', 'detail' => ''],
            ],
        ])
        ->assertSee('Choose a server')
        ->assertSee('hetzner-prod')
        ->assertSee('2 projects')
        ->assertSee('staging-box')
        ->assertSee('Select')
        ->assertDontSee('Confirm');
});

it('submits the picked option to shipbot', function () {
    fakeShipbot([
        'shipbot.test/chat/confirm' => Http::response([
            'thread_id' => 't-1',
            'messages' => [['role' => 'assistant', 'content' => 'Using staging-box.']],
            'pending_proposal' => null,
        ]),
    ]);

    Livewire::test(DeploymentAssistant::class)
        ->set('threadId', 't-1')
        ->set('pendingProposal', [
            'kind' => 'selection_proposal',
            'title' => 'Choose a server',
            'options' => [
                ['id' => 'srv-1', 'label' => 'hetzner-prod'],
                ['id' => 'srv-2', 'label' => 'staging-box'],
            ],
        ])
        ->set('selectedOptionId', 'srv-2')
        ->call('submitSelection')
        ->assertSet('pendingProposal', null)
        ->assertSet('selectedOptionId', null)
        ->assertDispatched('success')
        ->assertSee('Using staging-box.');

    Http::assertSent(fn ($request) => $request->url() === 'http://shipbot.test/chat/confirm'
        && $request['approved'] === true
        && $request['selected_option_id'] === 'srv-2');
});

it('rejects submitting an id the card never offered', function () {
    fakeShipbot();

    Livewire::test(DeploymentAssistant::class)
        ->set('threadId', 't-1')
        ->set('pendingProposal', [
            'kind' => 'selection_proposal',
            'title' => 'Choose a server',
            'options' => [['id' => 'srv-1', 'label' => 'hetzner-prod'], ['id' => 'srv-2', 'label' => 'staging-box']],
        ])
        ->set('selectedOptionId', 'srv-666')
        ->call('submitSelection')
        ->assertDispatched('error');

    Http::assertNotSent(fn ($request) => $request->url() === 'http://shipbot.test/chat/confirm');
});

it('rejects submitting before picking an option', function () {
    fakeShipbot();

    Livewire::test(DeploymentAssistant::class)
        ->set('threadId', 't-1')
        ->set('pendingProposal', [
            'kind' => 'selection_proposal',
            'title' => 'Choose a server',
            'options' => [['id' => 'srv-1', 'label' => 'hetzner-prod'], ['id' => 'srv-2', 'label' => 'staging-box']],
        ])
        ->call('submitSelection')
        ->assertDispatched('error');

    Http::assertNotSent(fn ($request) => $request->url() === 'http://shipbot.test/chat/confirm');
});

it('rejects submitting a selection when the pending card is not one', function () {
    fakeShipbot();

    Livewire::test(DeploymentAssistant::class)
        ->set('threadId', 't-1')
        ->set('pendingProposal', ['kind' => 'deployment_proposal'])
        ->set('selectedOptionId', 'srv-1')
        ->call('submitSelection')
        ->assertDispatched('error');

    Http::assertNotSent(fn ($request) => $request->url() === 'http://shipbot.test/chat/confirm');
});

it('rejects confirming when nothing is pending', function () {
    fakeShipbot();

    Livewire::test(DeploymentAssistant::class)
        ->call('confirmProposal')
        ->assertDispatched('error');

    Http::assertNotSent(fn ($request) => $request->url() === 'http://shipbot.test/chat/confirm');
});

it('keeps the prompt and shows an error when shipbot fails', function () {
    fakeShipbot([
        'shipbot.test/chat' => Http::response(['detail' => 'boom'], 500),
    ]);

    Livewire::test(DeploymentAssistant::class)
        ->set('prompt', 'deploy https://github.com/a/b')
        ->call('send')
        ->assertSet('prompt', 'deploy https://github.com/a/b')
        ->assertDispatched('error');
});

it('lets members send with their own identity', function () {
    fakeShipbot([
        'shipbot.test/chat' => Http::response([
            'thread_id' => 't-2',
            'messages' => [['role' => 'assistant', 'content' => 'On it.']],
            'pending_proposal' => null,
        ]),
    ]);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(DeploymentAssistant::class)
        ->set('prompt', 'deploy https://github.com/a/b')
        ->call('send')
        ->assertSet('threadId', 't-2')
        ->assertNotDispatched('error');

    Http::assertSent(fn ($request) => $request->url() === 'http://shipbot.test/chat' && $request['user_id'] === $member->id);
});

it('blocks sending when shipbot is not configured', function () {
    fakeShipbot();
    config(['services.shipbot.url' => null]);

    Livewire::test(DeploymentAssistant::class)
        ->set('prompt', 'deploy https://github.com/a/b')
        ->call('send')
        ->assertDispatched('error');

    Http::assertNotSent(fn ($request) => $request->url() === 'http://shipbot.test/chat');
});

it('loads the latest thread on mount', function () {
    Http::fake([
        'shipbot.test/threads/t-9*' => Http::response([
            'thread_id' => 't-9',
            'messages' => [['role' => 'assistant', 'content' => 'Welcome back']],
            'pending_proposal' => null,
        ]),
        'shipbot.test/threads*' => Http::response([
            'threads' => [['thread_id' => 't-9', 'title' => 'older chat', 'updated_at' => '2026-07-27T00:00:00+00:00']],
        ]),
    ]);

    Livewire::test(DeploymentAssistant::class)
        ->assertSet('threadId', 't-9')
        ->assertSee('Welcome back');
});

it('starts empty when shipbot is unreachable on mount', function () {
    Http::fake(fn () => throw new ConnectionException('down'));

    Livewire::test(DeploymentAssistant::class)
        ->assertSet('threadId', null)
        ->assertSet('messages', []);
});

it('renders the widget on the dashboard for an allowed admin', function () {
    fakeShipbot();

    $this->get('/')->assertOk()->assertSee('deployment-assistant');
});

it('does not render the widget when shipbot is not configured', function () {
    config(['services.shipbot.url' => null]);

    $this->get('/')->assertOk()->assertDontSee('deployment-assistant');
});

it('renders the widget for members too', function () {
    fakeShipbot();
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $this->get('/')->assertOk()->assertSee('deployment-assistant');
});
