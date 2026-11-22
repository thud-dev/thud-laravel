<?php

declare(strict_types=1);

namespace Thud\Tests\Fixtures;

use Illuminate\Mail\Mailable;

final class FixtureMail extends Mailable
{
    public function build(): self
    {
        return $this->subject('Welcome')->html('<p>Hello</p>');
    }
}
