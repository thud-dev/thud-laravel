<?php

declare(strict_types=1);

namespace Thud\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;

/**
 * A new authenticated request from the HTTP client factory, so Http::fake() still applies.
 *
 * Factory::createPendingRequest() only exists from Laravel 10. Calling a request method on the
 * factory forwards to a new pending request on every supported version.
 */
final class HttpRequest
{
    public static function withToken(Factory $http, string $token): PendingRequest
    {
        // @phpstan-ignore staticMethod.dynamicCall (Factory forwards instance calls to a new PendingRequest)
        return $http->withToken($token);
    }
}
