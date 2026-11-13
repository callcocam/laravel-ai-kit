<?php

namespace Callcocam\AiKit\Console;

use Callcocam\AiKit\AiRunner;
use Callcocam\AiKit\Compare\FieldMatcher;
use Callcocam\AiKit\Contracts\ComparesDocuments;
use Callcocam\AiKit\Documents\DocumentPreparer;
use Callcocam\AiKit\Providers\ProviderTerms;
use Callcocam\AiKit\Routing\Route;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Roda o mesmo agente, com o mesmo preparo de documento do app (PDF → texto ou
 * imagens), em vários provedores/modelos, contra um gabarito ao lado de cada
 * arquivo (`extrato.pdf.esperado.json` ou `extrato.esperado.json`), e mede
 * acerto campo a campo, tokens, custo estimado (`rates`) e tempo. É teste, não
 * uso: não passa pela AiGate nem grava em `ai_runs`.
 *
 * No gabarito, `_agent` (classe) e `_prompt` sobrescrevem --agent e --prompt
 * para aquele arquivo, para uma pasta só misturar tipos de documento.
 *
 *   php artisan ai:compare ~/amostra --agent='App\Ai\Agents\ReceiptReader' \
 *       --providers=cloudflare,anthropic:claude-haiku-4-5,anthropic:claude-sonnet-5
 */
class CompareCommand extends Command
{
    protected $signature = 'ai:compare
        {folder : pasta com os documentos e os gabaritos *.esperado.json}
        {--providers= : provedor[:modelo] separados por vírgula (padrão: ai.default)}
        {--agent= : classe do agente (ou `_agent` no gabarito)}
        {--prompt=Leia o documento e devolva os campos pedidos. : prompt quando o agente não implementa ComparesDocuments}
        {--csv= : onde gravar o CSV (padrão: na própria pasta)}
        {--dry-run : só conta as chamadas, sem chamar ninguém}
        {--force : não pede confirmação antes de gastar}';

    protected $description = 'Compara provedores/modelos de IA lendo documentos contra um gabarito';

    /**
     * @var list<array{file: string, route: string, hits: int, total: int, misses: string, input_tokens: int, output_tokens: int, cost: float|null, currency: string|null, ms: int, error: string|null}>
     */
    private array $results = [];

    public function handle(AiRunner $runner, DocumentPreparer $preparer): int
    {
        $folder = rtrim((string) $this->argument('folder'), '/');

        if (! File::isDirectory($folder)) {
            $this->error("Pasta não encontrada: {$folder}");

            return self::FAILURE;
        }

        $routes = array_map(Route::parse(...), array_values(array_filter(array_map('trim', explode(',', (string) ($this->option('providers') ?: config('ai.default')))))));
        $samples = $this->samples($folder);

        if ($samples === []) {
            $this->error('Nenhum documento com gabarito (*.esperado.json) na pasta.');

            return self::FAILURE;
        }

        $calls = count($samples) * count($routes);
        $this->line(count($samples).' documento(s) × '.count($routes).' provedor(es) = '.$calls.' chamada(s): '.implode(', ', array_map(fn (Route $route): string => $route->label(), $routes)));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && $this->input->isInteractive() && ! $this->confirm('As chamadas são reais e gastam a cota/crédito dos provedores. Continuar?', true)) {
            return self::FAILURE;
        }

        foreach ($samples as $file => $expected) {
            $agentClass = (string) ($expected['_agent'] ?? $this->option('agent'));

            if (! is_a($agentClass, Agent::class, true)) {
                $this->error(basename($file).': informe o agente (--agent ou `_agent` no gabarito).');

                continue;
            }

            $document = $preparer->from((string) file_get_contents($file), (string) File::mimeType($file));

            foreach ($routes as $route) {
                /** @var Agent $agent */
                $agent = app($agentClass);
                $prompt = $agent instanceof ComparesDocuments
                    ? $agent->comparisonPrompt($document['text'], basename($file))
                    : trim((string) ($expected['_prompt'] ?? $this->option('prompt'))."\n\n".($document['text'] ?? ''));

                $this->results[] = $this->measure($runner, $agent, $route, $prompt, $document['images'], basename($file), $expected);
            }
        }

        $this->report();

        return self::SUCCESS;
    }

    /**
     * Os documentos da pasta que têm gabarito, com o gabarito lido.
     *
     * @return array<string, array<string, mixed>>
     */
    private function samples(string $folder): array
    {
        $samples = [];

        foreach (File::files($folder) as $file) {
            $path = $file->getPathname();

            if (in_array(strtolower($file->getExtension()), ['json', 'csv'], true) || str_starts_with($file->getFilename(), '.')) {
                continue;
            }

            $answer = collect([$path.'.esperado.json', $file->getPath().'/'.$file->getFilenameWithoutExtension().'.esperado.json'])->first(fn (string $candidate): bool => File::exists($candidate));

            if ($answer === null) {
                $this->warn($file->getFilename().': sem gabarito, fica de fora.');

                continue;
            }

            $expected = json_decode((string) File::get($answer), true);

            if (! is_array($expected)) {
                $this->warn(basename($answer).': JSON inválido, fica de fora.');

                continue;
            }

            $samples[$path] = $expected;
        }

        ksort($samples);

        return $samples;
    }

    /**
     * @param  list<mixed>  $images
     * @param  array<string, mixed>  $expected
     * @return array{file: string, route: string, hits: int, total: int, misses: string, input_tokens: int, output_tokens: int, cost: float|null, currency: string|null, ms: int, error: string|null}
     */
    private function measure(AiRunner $runner, Agent $agent, Route $route, string $prompt, array $images, string $file, array $expected): array
    {
        $started = hrtime(true);
        $response = null;
        $error = null;

        try {
            $response = $runner->send($agent, $route, $prompt, $images);
        } catch (Throwable $exception) {
            $error = class_basename($exception);
        }

        $ms = (int) ((hrtime(true) - $started) / 1_000_000);
        $score = FieldMatcher::compare($expected, $response === null ? [] : $this->answer($response));
        $terms = ProviderTerms::for($route->provider);
        $input = $response->usage->inputTokens ?? 0;
        $output = $response->usage->outputTokens ?? 0;

        return [
            'file' => $file,
            'route' => $route->label(),
            'hits' => $score['hits'],
            'total' => $score['total'],
            'misses' => implode(' ', $score['misses']),
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost' => $response === null ? null : $terms->cost($response->meta->model ?? $route->modelOrDefault(), $input, $output),
            'currency' => $terms->currency,
            'ms' => $ms,
            'error' => $error,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function answer(AgentResponse $response): array
    {
        if ($response instanceof StructuredAgentResponse) {
            return $response->toArray();
        }

        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $response->text) ?? '');
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function report(): void
    {
        $this->table(
            ['Arquivo', 'Provedor', 'Acerto', 'Tokens', 'Custo', 'Tempo', 'Errou'],
            array_map(fn (array $row): array => [
                $row['file'],
                $row['route'],
                $row['error'] ?? "{$row['hits']}/{$row['total']}",
                "{$row['input_tokens']}/{$row['output_tokens']}",
                $this->money($row['cost'], $row['currency']),
                $row['ms'].' ms',
                $row['misses'],
            ], $this->results),
        );

        $summary = [];

        foreach (collect($this->results)->groupBy('route') as $route => $rows) {
            $total = $rows->sum('total');
            $costs = $rows->pluck('cost')->filter(fn ($cost): bool => $cost !== null);
            $summary[] = [
                $route,
                $rows->count(),
                $total === 0 ? '-' : number_format(100 * $rows->sum('hits') / $total, 1, ',', '.').'%',
                $costs->isEmpty() ? '-' : $this->money((float) $costs->avg(), $rows->first()['currency']),
                (int) round((float) $rows->avg('ms')).' ms',
                $rows->whereNotNull('error')->count(),
            ];
        }

        $this->newLine();
        $this->table(['Provedor', 'Leituras', 'Acerto', 'Custo médio', 'Tempo médio', 'Falhas'], $summary);

        $csv = (string) ($this->option('csv') ?: rtrim((string) $this->argument('folder'), '/').'/ai-compare-'.now()->format('Ymd-His').'.csv');
        $handle = fopen($csv, 'w');

        if ($handle === false) {
            $this->error("Não deu para gravar o CSV em {$csv}");

            return;
        }

        fputcsv($handle, array_keys($this->results[0] ?? ['file' => null]), escape: '\\');

        foreach ($this->results as $row) {
            fputcsv($handle, $row, escape: '\\');
        }

        fclose($handle);
        $this->info("CSV: {$csv}");
    }

    private function money(?float $value, ?string $currency): string
    {
        if ($value === null) {
            return '-';
        }

        return $currency === 'USD'
            ? 'US$ '.number_format($value, 5, ',', '.')
            : number_format($value, 1, ',', '.').' '.($currency ?? '');
    }
}
