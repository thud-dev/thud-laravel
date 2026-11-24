<?php

declare(strict_types=1);

namespace Thud\Hooks;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Thud\Recording\RunContext;
use Thud\Recording\RunStack;
use Thud\Support\Safely;
use Thud\Transport\Buffer;
use Thud\Transport\FlushOnTerminate;

/**
 * Route middleware, alias `thud`, with an optional provider: ->middleware('thud:stripe').
 *
 * Records a webhook run named after the route and its response status. For known providers it
 * reads only the event ID and type from the payload, to link with payment reconciliation.
 *
 * Always registered (so routes using the alias keep working with THUD_ENABLED=false), and a
 * pass-through when Thud is disabled.
 */
final class WebhookMiddleware
{
    public const PROVIDERS = ['stripe', 'paystack', 'paddle', 'lemon_squeezy', 'flutterwave'];

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    public function handle(Request $request, Closure $next, ?string $provider = null): mixed
    {
        $run = Safely::run(fn () => $this->start($request, $provider), key: 'webhook');

        if ($run === null) {
            return $next($request);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            Safely::run(function () use ($request, $run, $e): void {
                $run->failWith($e);
                $this->end($request, $run, 500);
            }, key: 'webhook');

            throw $e;
        }

        $status = $response instanceof Response ? $response->getStatusCode() : 200;
        Safely::run(function () use ($request, $run, $status, $response): void {
            // Laravel's handler turns a crash into a 500 response and keeps the exception on it.
            $exception = is_object($response) && property_exists($response, 'exception') ? $response->exception : null;
            $run->failWith($exception instanceof Throwable ? $exception : null);
            $this->end($request, $run, $status);
        }, key: 'webhook');

        return $response;
    }

    private function start(Request $request, ?string $provider): ?RunContext
    {
        if (! (bool) $this->config->get('thud.enabled') || ! (bool) $this->config->get('thud.record.webhooks')) {
            return null;
        }

        $run = new RunContext(RunContext::TYPE_WEBHOOK, self::nameFor($request), owner: $request);
        $run->providerEvent = $provider !== null ? self::providerEvent($provider, $request) : null;

        $this->container->make(RunStack::class)->push($run);

        return $run;
    }

    private function end(Request $request, RunContext $run, int $status): void
    {
        $this->container->make(RunStack::class)->popOwnedBy($request);

        $run->httpStatus = $status;
        $run->finish($status >= 400 ? RunContext::STATUS_FAILED : RunContext::STATUS_SUCCEEDED);

        $this->container->make(Buffer::class)->add($run->toPayload());
        $this->container->make(FlushOnTerminate::class)->runFinished();
    }

    private static function nameFor(Request $request): string
    {
        $route = $request->route();

        if ($route instanceof Route) {
            return $route->getName() ?? $request->method().' '.$route->uri();
        }

        return $request->method().' '.$request->path();
    }

    /**
     * Reads only the provider's event ID and type: never amounts, customers or other payload data.
     *
     * @return array{provider: string, id: string, type: string|null}|null
     */
    public static function providerEvent(string $provider, Request $request): ?array
    {
        [$id, $type] = match ($provider) {
            'stripe' => [$request->input('id'), $request->input('type')],
            'paystack' => [$request->input('data.id'), $request->input('event')],
            'paddle' => [$request->input('event_id'), $request->input('event_type')],
            'lemon_squeezy' => [$request->input('data.id'), $request->input('meta.event_name')],
            'flutterwave' => [$request->input('data.id'), $request->input('event') ?? $request->input('event.type')],
            default => [null, null],
        };

        if (! is_scalar($id) || (string) $id === '') {
            return null;
        }

        return [
            'provider' => $provider,
            'id' => substr((string) $id, 0, 100),
            'type' => is_string($type) ? substr($type, 0, 100) : null,
        ];
    }
}
