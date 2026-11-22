<?php

declare(strict_types=1);

namespace Thud\Expectations\Concerns;

/**
 * Fluent ->label('Subscription is active') for every expectation.
 */
trait Labelled
{
    private ?string $label = null;

    public function label(string $label): static
    {
        $this->label = mb_substr(trim($label), 0, 191);

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }
}
