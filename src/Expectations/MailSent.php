<?php

declare(strict_types=1);

namespace Thud\Expectations;

use Thud\Expectations\Concerns\Labelled;
use Thud\Recording\RunContext;
use Thud\Recording\SideEffectCounter;

/**
 * During-run check: a mailable (or mail notification) of the given class was sent, optionally to
 * a given address. The address is compared as a sha256 hash and never leaves the app.
 */
final class MailSent implements Expectation
{
    use Labelled;

    private ?string $recipientHash = null;

    public function __construct(private readonly string $mailable) {}

    public function to(string $address): self
    {
        $this->recipientHash = SideEffectCounter::hashAddress($address);

        return $this;
    }

    public function evaluate(RunContext $run): Result
    {
        $name = class_basename($this->mailable);

        if ($run->counter->mailCount($this->mailable) === 0) {
            return Result::failed("No {$name} was sent");
        }

        if ($this->recipientHash !== null && ! $run->counter->mailSentTo($this->mailable, $this->recipientHash)) {
            return Result::failed("{$name} was sent, but not to the expected address");
        }

        return Result::passed();
    }
}
