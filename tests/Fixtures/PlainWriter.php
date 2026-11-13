<?php

namespace Callcocam\AiKit\Tests\Fixtures;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class PlainWriter implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Escreva.';
    }
}
