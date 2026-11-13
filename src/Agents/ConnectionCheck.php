<?php

namespace Callcocam\AiKit\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * `ai:check`: uma resposta estruturada curta que prova que o provedor, a chave e
 * o modelo funcionam, sem nada de nenhum escopo.
 */
class ConnectionCheck implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Você confirma que está funcionando. Responda sempre em português do Brasil, em uma frase curta.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ok' => $schema->boolean()->required(),
            'reply' => $schema->string()->required(),
        ];
    }
}
