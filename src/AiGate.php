<?php

namespace Callcocam\AiKit;

use Callcocam\AiKit\Contracts\AiPolicy;
use Callcocam\AiKit\Contracts\RestrictsTasks;
use Callcocam\AiKit\Contracts\RouteResolver;
use Callcocam\AiKit\Exceptions\AiUnavailable;
use Callcocam\AiKit\Providers\ProviderTerms;
use Callcocam\AiKit\Routing\Input;
use Callcocam\AiKit\Routing\Route;

/**
 * Se um escopo pode chamar a IA agora, para uma tarefa e um tipo de entrada.
 * Toda chamada passa por aqui (AiRunner): a restrição do projeto, a chave do
 * servidor (AI_ENABLED), a chave do provedor da rota, o escopo ter a IA ligada,
 * sempre um provedor que não treina com o conteúdo, a cota do mês do grupo da
 * tarefa e um provedor que leia o que vai anexado.
 * Aceite de LGPD e regras de domínio são do projeto, antes de chegar aqui.
 */
class AiGate
{
    public function __construct(
        private readonly AiPolicy $policy,
        private readonly RouteResolver $routes,
        private readonly AiUsage $usage,
    ) {}

    public function route(?string $task = null, Input $input = Input::Text): Route
    {
        return $this->routes->resolve($task, $input);
    }

    public function provider(?string $task = null, Input $input = Input::Text): string
    {
        return $this->route($task, $input)->provider;
    }

    public function terms(?string $task = null, Input $input = Input::Text): ProviderTerms
    {
        return ProviderTerms::for($this->provider($task, $input));
    }

    public function configured(?string $task = null, Input $input = Input::Text): bool
    {
        return $this->terms($task, $input)->configured();
    }

    /**
     * Quantas chamadas o escopo pode fazer no mês no grupo da tarefa (null = sem limite).
     */
    public function monthlyBudget(mixed $scope, ?string $task = null): ?int
    {
        return $this->policy->monthlyBudget($scope, $task);
    }

    /**
     * O motivo de a IA não poder ser usada, ou null quando pode.
     */
    public function blockedReason(mixed $scope, ?string $task = null, Input $input = Input::Text): ?string
    {
        if ($task !== null && $this->policy instanceof RestrictsTasks) {
            $allowed = $this->policy->allows($scope, $task);

            if ($allowed !== true) {
                return $allowed;
            }
        }

        $terms = $this->terms($task, $input);
        $budget = $this->monthlyBudget($scope, $task);

        return match (true) {
            ! config('ai-kit.enabled') => 'server_off',
            ! $terms->configured() => 'not_configured',
            ! $this->policy->enabled($scope) => 'scope_off',
            $terms->trainsOnInput => 'trains_on_data',
            $budget !== null && $this->usage->runsThisMonth($scope, task: $task) >= $budget => 'budget',
            $input !== Input::Text && $terms->retainsInput && config('ai-kit.attachments_require_no_retention') => 'keeps_data',
            $input->needsVision() && ! $terms->vision => 'no_vision',
            $input === Input::Audio && ! $terms->audio => 'no_audio',
            default => null,
        };
    }

    /**
     * @throws AiUnavailable
     */
    public function ensure(mixed $scope, ?string $task = null, Input $input = Input::Text): void
    {
        $reason = $this->blockedReason($scope, $task, $input);

        if ($reason !== null) {
            throw new AiUnavailable($reason);
        }
    }
}
