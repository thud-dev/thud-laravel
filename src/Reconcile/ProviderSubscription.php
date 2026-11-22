<?php

declare(strict_types=1);

namespace Thud\Reconcile;

/**
 * An active subscription as a provider reports it.
 */
final class ProviderSubscription
{
    public function __construct(
        public readonly string $ref,
        public readonly ?string $customerRef,
        public readonly string $status,
    ) {}
}
