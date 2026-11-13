<?php

namespace Callcocam\AiKit\Console;

use Callcocam\AiKit\AiUsage;
use Illuminate\Console\Command;

/**
 * O uso da IA a partir de `ai_runs`: o mês de um escopo por grupo e
 * provedor/modelo, com o custo estimado, e os últimos dias contra a cota
 * diária do provedor.
 *
 *   php artisan ai:usage [escopo] [--provider=cloudflare] [--days=14]
 */
class UsageCommand extends Command
{
    protected $signature = 'ai:usage
        {scope? : id do escopo (o valor da coluna ai-kit.runs.scope_column)}
        {--provider= : provedor da cota diária (padrão: o da rota de texto)}
        {--days=14 : quantos dias mostrar}';

    protected $description = 'Mostra o uso e o custo estimado da IA';

    public function handle(AiUsage $usage): int
    {
        $scope = $this->argument('scope');

        if ($scope !== null) {
            $rows = $usage->month($scope);
            $this->line("Mês de {$scope}:");
            $this->table(['Grupo', 'Provedor', 'Modelo', 'Chamadas', 'Tokens', 'Custo'], array_map(fn (array $row): array => [
                $row['group'],
                $row['provider'],
                $row['model'] ?? '-',
                $row['runs'],
                "{$row['input_tokens']}/{$row['output_tokens']}",
                $row['cost'] === null ? '-' : number_format($row['cost'], $row['currency'] === 'USD' ? 4 : 1, ',', '.').' '.$row['currency'],
            ], $rows));
        }

        $daily = $usage->daily($this->option('provider') ?: null, max(1, (int) $this->option('days')));

        if ($daily === null) {
            $this->line('O provedor não declara cota diária (ai-kit.providers.*.daily_allowance).');

            return self::SUCCESS;
        }

        $this->line("Cota diária de {$daily['provider']}: {$daily['used']} de {$daily['allowance']} hoje (UTC).");
        $this->table(['Dia', 'Usado', '%'], array_map(fn (array $day): array => [
            $day['date'],
            $day['used'],
            number_format(100 * $day['used'] / $daily['allowance'], 1, ',', '.').'%',
        ], $daily['days']));

        return self::SUCCESS;
    }
}
