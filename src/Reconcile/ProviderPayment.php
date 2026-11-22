<?php

declare(strict_types=1);

namespace Thud\Reconcile;

use Carbon\CarbonImmutable;

/**
 * A successful payment as a provider reports it. References only: never amounts or emails.
 */
final class ProviderPayment
{
    public function __construct(
        public readonly string $ref,
        public readonly ?string $customerRef,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
