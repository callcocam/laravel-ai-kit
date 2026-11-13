<?php

namespace Callcocam\AiKit\Testing;

use Callcocam\AiKit\Contracts\AiPolicy;
use Callcocam\AiKit\Contracts\RestrictsTasks;
use Callcocam\AiKit\Support\Scope;
use Callcocam\AiKit\Support\TaskGroups;

/**
 * Uma política para os testes do projeto, montada na hora:
 *
 *   AiKit::fake(FakePolicy::make()->budget(3)->budget(10, 'whatsapp')->off('team-2')->deny('price_tag_read', 'plan'));
 */
class FakePolicy implements AiPolicy, RestrictsTasks
{
    /** @var list<string|int> */
    private array $off = [];

    /** @var array<string, int|null> */
    private array $budgets = [];

    /** @var array<string, string> */
    private array $denied = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * Desliga a IA destes escopos (motivo `scope_off`).
     */
    public function off(mixed ...$scopes): self
    {
        array_push($this->off, ...array_map(Scope::key(...), $scopes));

        return $this;
    }

    /**
     * A cota do mês de um grupo de tarefas (padrão: o grupo `default`).
     */
    public function budget(?int $runs, string $group = TaskGroups::DEFAULT): self
    {
        $this->budgets[$group] = $runs;

        return $this;
    }

    /**
     * Recusa a tarefa com o motivo dado, para qualquer escopo.
     */
    public function deny(string $task, string $reason): self
    {
        $this->denied[$task] = $reason;

        return $this;
    }

    public function enabled(mixed $scope): bool
    {
        return ! in_array(Scope::key($scope), $this->off, true);
    }

    public function monthlyBudget(mixed $scope, ?string $task): ?int
    {
        return $this->budgets[TaskGroups::of($task)] ?? null;
    }

    public function allows(mixed $scope, string $task): true|string
    {
        return $this->denied[$task] ?? true;
    }
}
