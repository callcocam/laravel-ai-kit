<?php

namespace Callcocam\AiKit\Contracts;

/**
 * O que é do projeto na decisão de chamar a IA. O escopo é o dono da chamada
 * como o projeto o passa ao AiRunner (o id da equipe, o model da congregação…).
 */
interface AiPolicy
{
    /**
     * O escopo deixou a IA ligada (ex.: Configurações → Inteligência artificial).
     */
    public function enabled(mixed $scope): bool;

    /**
     * Quantas chamadas o escopo pode fazer no mês no grupo da tarefa (null = sem
     * limite). Só as chamadas do mesmo grupo contam (config ai-kit.groups).
     */
    public function monthlyBudget(mixed $scope, ?string $task): ?int;
}
