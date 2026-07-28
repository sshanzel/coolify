<?php

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/
uses(TestCase::class)->in('Feature', 'v4/Feature', 'v4/Browser');

/*
|--------------------------------------------------------------------------
| Test Hooks
|--------------------------------------------------------------------------
|
| Global hooks that run before/after each test.
|
*/
beforeEach(function () {
    // Flush the Once memoization cache to ensure tests get fresh data
    Once::flush();

    // Flush the Server identity map cache to ensure tests get fresh data
    Server::flushIdentityMap();
});

function loginAndSkipBoarding(string $email = 'test@example.com', string $password = 'password'): mixed
{
    return visit('/login')
        ->fill('email', $email)
        ->fill('password', $password)
        ->click('Login')
        ->click('Skip Setup');
}

/*
|--------------------------------------------------------------------------
| MCP Helpers
|--------------------------------------------------------------------------
|
| Shared by the tests/Feature/Mcp suites: JSON-RPC calls against the /mcp
| endpoint plus audit-log expectations.
|
*/

function mcpPost(array $payload, ?string $token = null)
{
    $headers = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
    ];
    if ($token) {
        $headers['Authorization'] = 'Bearer '.$token;
    }

    return test()->withHeaders($headers)->postJson('/mcp', $payload);
}

function mcpListTools(string $token)
{
    return mcpPost([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => ['per_page' => 100],
    ], $token);
}

function mcpCallTool(string $token, string $name, array $arguments = [])
{
    return mcpPost([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => $name,
            'arguments' => (object) $arguments,
        ],
    ], $token);
}

function mcpToolJson($response): array
{
    return json_decode($response->json('result.content.0.text'), true);
}

function expectMcpAuditLog(array $expected): void
{
    $auditChannel = Mockery::mock();

    Log::shouldReceive('channel')
        ->with('audit')
        ->once()
        ->andReturn($auditChannel);

    $auditChannel
        ->shouldReceive('info')
        ->once()
        ->with('mcp.tool.called', Mockery::on(fn (array $context) => collect($expected)->every(
            fn ($value, $key) => data_get($context, $key) === $value,
        )));
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

// expect()->extend('toBeOne', function () {
//     return $this->toBe(1);
// });

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// function something()
// {
//     // ..
// }
