<?php

namespace Callcocam\AiKit\Policies;

use Callcocam\AiKit\Contracts\AiPolicy;

/**
 * A política padrão: todo escopo com a IA ligada e sem cota no mês.
 */
class AllowAll implements AiPolicy
{
    public function enabled(mixed $scope): bool
    {
        return true;
    }

    public function monthlyBudget(mixed $scope, ?string $task): ?int
    {
        return null;
    }
}
