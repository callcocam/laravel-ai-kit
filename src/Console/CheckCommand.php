<?php

namespace Callcocam\AiKit\Console;

use Callcocam\AiKit\Agents\ConnectionCheck;
use Callcocam\AiKit\AiRunner;
use Callcocam\AiKit\Contracts\RouteResolver;
use Callcocam\AiKit\Documents\DocumentPreparer;
use Callcocam\AiKit\Providers\ProviderTerms;
use Callcocam\AiKit\Routing\Route;
use Illuminate\Console\Command;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Prova que cada provedor/modelo das rotas funciona: uma pergunta curta, sem
 * nada de nenhum escopo. Testa mesmo com AI_ENABLED desligado (para conferir
 * antes de ligar) e não grava em `ai_runs`: não é uso de ninguém. Avisa quando
 * falta o poppler-utils (sem ele nenhum PDF é lido).
 *
 *   php artisan ai:check [--route=anthropic:claude-haiku-4-5]
 */
class CheckCommand extends Command
{
    protected $signature = 'ai:check {--route=* : provedor[:modelo] a testar no lugar das rotas configuradas}';

    protected $description = 'Testa a conexão com cada provedor de IA configurado';

    public function handle(AiRunner $runner, RouteResolver $routes): int
    {
        $this->line('IA no servidor: '.(config('ai-kit.enabled') ? 'ligada' : 'desligada (AI_ENABLED)'));

        if (! DocumentPreparer::available()) {
            $this->warn('poppler-utils não encontrado (pdftotext/pdftoppm): nenhum PDF será lido.');
        }

        $options = (array) $this->option('route');
        $list = $options === [] ? $routes->all() : array_map(fn ($value): Route => Route::parse((string) $value), $options);
        $failed = false;

        foreach ($list as $route) {
            $terms = ProviderTerms::for($route->provider);
            $this->newLine();
            $this->line("<options=bold>{$terms->label}</> ({$route->label()}) · ".$this->describe($terms));

            if (! $terms->configured()) {
                $this->error("  Sem chave em config/ai.php → providers.{$route->provider}.key");
                $failed = true;

                continue;
            }

            $started = hrtime(true);

            try {
                $response = $runner->send(new ConnectionCheck, $route, 'Confirme que você está funcionando.');
            } catch (Throwable $exception) {
                // A mensagem do provedor pode trazer detalhes da conta: só a classe.
                $this->error('  Falhou: '.class_basename($exception));
                $failed = true;

                continue;
            }

            $ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->info('  '.($response instanceof StructuredAgentResponse ? (string) ($response['reply'] ?? '') : $response->text));
            $this->line('  Modelo: '.($response->meta->model ?? $route->modelOrDefault())." · tokens {$response->usage->inputTokens}/{$response->usage->outputTokens} · {$ms} ms");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function describe(ProviderTerms $terms): string
    {
        if (! $terms->known) {
            return 'termos não declarados em ai-kit.providers: conta como "treina e guarda"';
        }

        $retention = $terms->retainsInput ? 'guarda'.($terms->retentionDays ? " {$terms->retentionDays} dias" : '') : 'não guarda';

        return implode(' · ', [
            $terms->trainsOnInput ? 'TREINA com os dados' : 'não treina',
            $retention,
            $terms->vision ? 'lê imagem' : 'sem imagem',
            'conferido em '.($terms->checkedOn ?? '?'),
        ]);
    }
}
