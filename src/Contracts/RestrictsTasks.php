<?php

namespace Callcocam\AiKit\Contracts;

/**
 * Opcional, na AiPolicy: recusar uma tarefa com um motivo do projeto (ex.: o
 * plano da família não inclui a leitura de foto → 'plan'). É conferido antes de
 * tudo, para a tela mostrar o motivo do projeto mesmo com a IA desligada.
 */
interface RestrictsTasks
{
    /**
     * true quando pode; o motivo (AiUnavailable::$reason) quando não.
     */
    public function allows(mixed $scope, string $task): true|string;
}
