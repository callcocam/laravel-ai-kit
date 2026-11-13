<?php

namespace Callcocam\AiKit;

use Callcocam\AiKit\Contracts\AiPolicy;
use Callcocam\AiKit\Contracts\RouteResolver;
use Callcocam\AiKit\Models\AiRun;
use Callcocam\AiKit\Providers\ProviderTerms;
use Callcocam\AiKit\Support\Scope;
use Callcocam\AiKit\Support\TaskGroups;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Quanto da IA foi usado, a partir de `ai_runs`: as chamadas do mês de um escopo
 * (por grupo de tarefas, que é como as cotas contam), o custo estimado pelas
 * `rates` de cada provedor e o uso de cada dia contra a cota grátis do provedor
 * (`daily_allowance`). A cota do dia é da conta do provedor, então o dia soma
 * todos os escopos do servidor, e daqui só saem totais.
 */
class AiUsage
{
    public const DAYS = 14;

    public function __construct(
        private readonly AiPolicy $policy,
        private readonly RouteResolver $routes,
    ) {}

    /**
     * Chamadas do escopo no mês: todas, as de um grupo (`group`) ou as do grupo
     * de uma tarefa (`task`). Falhas e respostas recusadas contam: o provedor foi chamado.
     */
    public function runsThisMonth(mixed $scope, ?string $group = null, ?string $task = null): int
    {
        return $this->monthQuery($scope, $task !== null ? TaskGroups::of($task) : $group)->count();
    }

    /**
     * O mês do escopo por grupo e por provedor/modelo: chamadas, tokens e custo
     * estimado (na unidade do provedor; null quando o modelo não tem preço).
     *
     * @return list<array{group: string, provider: string, model: string|null, runs: int, input_tokens: int, output_tokens: int, cost: float|null, currency: string|null}>
     */
    public function month(mixed $scope): array
    {
        $rows = [];

        foreach ([...array_keys(TaskGroups::all()), TaskGroups::DEFAULT] as $group) {
            $this->monthQuery($scope, $group)
                ->selectRaw('provider, model, count(*) as runs, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens')
                ->groupBy('provider', 'model')
                ->orderBy('provider')
                ->toBase()
                ->get()
                ->each(function (object $row) use ($group, &$rows): void {
                    $terms = ProviderTerms::for((string) $row->provider);
                    $rows[] = [
                        'group' => $group,
                        'provider' => (string) $row->provider,
                        'model' => $row->model === null ? null : (string) $row->model,
                        'runs' => (int) $row->runs,
                        'input_tokens' => (int) $row->input_tokens,
                        'output_tokens' => (int) $row->output_tokens,
                        'cost' => $terms->cost($row->model === null ? null : (string) $row->model, (int) $row->input_tokens, (int) $row->output_tokens),
                        'currency' => $terms->currency,
                    ];
                });
        }

        return $rows;
    }

    /**
     * O uso de cada um dos últimos dias contra a cota diária do provedor (dias
     * UTC, como o provedor conta), ou null quando o provedor não declara cota.
     * Padrão: o provedor da rota de texto.
     *
     * @return array{provider: string, used: int, allowance: int, days: list<array{date: string, used: int}>}|null
     */
    public function daily(?string $provider = null, int $days = self::DAYS): ?array
    {
        $provider ??= $this->routes->resolve(null)->provider;
        $terms = ProviderTerms::for($provider);

        if (($terms->dailyAllowance ?? 0) <= 0) {
            return null;
        }

        $today = Carbon::now('UTC')->startOfDay();
        $used = [];

        $this->query()
            ->where('provider', $provider)
            ->where('created_at', '>=', $today->copy()->subDays($days - 1))
            ->selectRaw('date(created_at) as day, model, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens')
            ->groupByRaw('date(created_at), model')
            ->toBase()
            ->get()
            ->each(function (object $row) use ($terms, &$used): void {
                $cost = $terms->cost($row->model === null ? null : (string) $row->model, (int) $row->input_tokens, (int) $row->output_tokens);

                if ($cost === null) {
                    return;
                }

                $day = substr((string) $row->day, 0, 10);
                $used[$day] = ($used[$day] ?? 0) + $cost;
            });

        $list = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = $today->copy()->subDays($offset)->toDateString();
            $list[] = ['date' => $date, 'used' => (int) round($used[$date] ?? 0)];
        }

        return ['provider' => $provider, 'used' => $list[$days - 1]['used'], 'allowance' => (int) $terms->dailyAllowance, 'days' => $list];
    }

    /**
     * Para um cartão de uso: o dia do provedor e o mês do escopo no grupo da
     * tarefa, com a cota da política (null = sem limite).
     *
     * @return array{daily: array{provider: string, used: int, allowance: int, days: list<array{date: string, used: int}>}|null, month: array{runs: int, limit: int|null}}
     */
    public function summary(mixed $scope, ?string $task = null): array
    {
        return [
            'daily' => $this->daily($this->routes->resolve($task)->provider),
            'month' => ['runs' => $this->runsThisMonth($scope, task: $task), 'limit' => $this->policy->monthlyBudget($scope, $task)],
        ];
    }

    /**
     * @return Builder<AiRun>
     */
    private function monthQuery(mixed $scope, ?string $group): Builder
    {
        return $this->query()
            ->ownedBy(Scope::key($scope))
            ->where('created_at', '>=', now()->startOfMonth())
            ->when($group === TaskGroups::DEFAULT && TaskGroups::grouped() !== [], fn (Builder $query) => $query->whereNotIn('task', TaskGroups::grouped()))
            ->when($group !== null && $group !== TaskGroups::DEFAULT, fn (Builder $query) => $query->whereIn('task', TaskGroups::all()[$group] ?? []));
    }

    /**
     * @return Builder<AiRun>
     */
    private function query(): Builder
    {
        /** @var class-string<AiRun> $model */
        $model = config('ai-kit.runs.model', AiRun::class);

        return $model::query();
    }
}
