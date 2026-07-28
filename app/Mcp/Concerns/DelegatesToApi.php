<?php

namespace App\Mcp\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Routing\Route;

trait DelegatesToApi
{
    /**
     * Build an in-process JSON request for the /api/v1 controllers: same
     * authenticated user/token as the MCP call (auth()->user() is set by the
     * auth:sanctum middleware on /mcp), a JSON body, and — when given — route
     * parameters the controller reads via $request->route('...').
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $routeParams
     */
    protected function synthesizeJsonRequest(string $method, string $uri, array $payload, array $routeParams = []): HttpRequest
    {
        // The payload rides along twice on purpose: as the raw JSON body (for
        // validateIncomingRequest() and the json input source) and as request
        // parameters (for controllers reading $request->get(), which consults
        // the Symfony bags that Request::create() does not fill from content).
        $request = HttpRequest::create(
            $uri,
            $method,
            $payload,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($payload),
        );

        $request->setUserResolver(fn () => auth()->user());

        if ($routeParams !== []) {
            $route = new Route([$method], $uri, []);
            $route->bind($request);
            foreach ($routeParams as $key => $value) {
                $route->setParameter($key, $value);
            }
            $request->setRouteResolver(fn () => $route);
        }

        return $request;
    }

    /**
     * Invoke an API controller method in-process and unwrap its JsonResponse.
     *
     * @return array{status: int, body: array<array-key, mixed>}
     */
    protected function callApi(string $controller, string $method, HttpRequest $request): array
    {
        $response = app($controller)->{$method}($request);

        $body = $response instanceof JsonResponse
            ? (array) $response->getData(true)
            : (array) json_decode((string) $response->getContent(), true);

        return ['status' => $response->getStatusCode(), 'body' => $body];
    }

    /**
     * Flatten an API error body ({message, errors?}) into one MCP error string.
     *
     * @param  array{status: int, body: array<array-key, mixed>}  $result
     */
    protected function apiErrorMessage(array $result): string
    {
        $message = (string) data_get($result['body'], 'message', 'Request failed.');
        $errors = data_get($result['body'], 'errors');
        if (is_array($errors) && $errors !== []) {
            $message = trim($message.' '.collect($errors)->flatten()->implode(' '));
        }

        return $message;
    }
}
