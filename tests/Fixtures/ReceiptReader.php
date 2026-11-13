<?php

namespace Callcocam\AiKit\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class ReceiptReader implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Leia o comprovante.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->string()->required(),
            'payee' => $schema->string()->required(),
        ];
    }
}
