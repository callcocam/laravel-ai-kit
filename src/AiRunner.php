<?php

namespace Callcocam\AiKit;

use Callcocam\AiKit\Exceptions\AiUnavailable;
use Callcocam\AiKit\Models\AiRun;
use Callcocam\AiKit\Routing\Input;
use Callcocam\AiKit\Routing\Route;
use Callcocam\AiKit\Support\Scope;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * A única porta da aplicação para a IA: confere a AiGate, chama o agente no
 * provedor e modelo da rota e registra a chamada em `ai_runs` (escopo, quem
 * pediu, tarefa, provedor, modelo, hash do prompt, ids do que foi usado, tokens,
 * duração, resultado), nunca o texto. Agente estruturado cuja resposta não traz
 * um campo exigido é registrado como `rejected_schema` e recusado, nunca
 * "melhor esforço".
 */
class AiRunner
{
    /**
     * O registro da última chamada feita por este runner (quem chama liga o seu resultado a ele).
     */
    public ?AiRun $lastRun = null;

    public function __construct(private readonly AiGate $gate) {}

    /**
     * @param  string  $prompt  os fatos e a pergunta (o texto do documento, quando houver)
     * @param  mixed  $scope  o dono da chamada: id ou model (equipe, congregação…)
     * @param  mixed  $user  quem pediu: id, model ou null (o sistema)
     * @param  list<mixed>  $attachments  arquivos enviados com o prompt (páginas, fotos, áudio)
     * @param  array<string, mixed>  $inputRefs  ids dos registros usados (nunca o conteúdo)
     * @param  list<string>  $requiredKeys  chaves que a resposta estruturada precisa ter
     * @param  int|null  $timeout  teto desta chamada em segundos (padrão: o da rota)
     *
     * @throws AiUnavailable
     */
    public function run(Agent $agent, string $task, string $prompt, mixed $scope, mixed $user = null, array $attachments = [], array $inputRefs = [], array $requiredKeys = [], ?int $timeout = null): AgentResponse
    {
        $input = Input::fromAttachments($attachments);
        $this->gate->ensure($scope, $task, $input);
        $route = $this->gate->route($task, $input);

        /** @var class-string<AiRun> $model */
        $model = config('ai-kit.runs.model', AiRun::class);
        $started = hrtime(true);
        $record = fn (string $status, ?AgentResponse $response = null, ?string $error = null) => $this->lastRun = $model::query()->forceCreate([
            $model::scopeColumn() => Scope::key($scope),
            'user_id' => Scope::keyOrNull($user),
            'task' => $task,
            'provider' => $route->provider,
            'model' => $response?->meta->model ?? $route->modelOrDefault(),
            'prompt_hash' => hash('sha256', $prompt),
            'input_refs' => $inputRefs === [] ? null : $inputRefs,
            'attachments' => count($attachments),
            'input_tokens' => $response?->usage->inputTokens ?? 0,
            'output_tokens' => $response?->usage->outputTokens ?? 0,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'status' => $status,
            'error' => $error === null ? null : mb_substr($error, 0, 255),
            'created_at' => now(),
        ]);

        try {
            $response = $this->send($agent, $route, $prompt, $attachments, $timeout);
        } catch (Throwable $exception) {
            $record(AiRun::STATUS_FAILED, error: class_basename($exception));
            // A mensagem do provedor pode ecoar o prompt (o documento): só a classe vai ao log.
            Log::warning('AI call failed', ['task' => $task, 'scope' => Scope::key($scope), 'error' => class_basename($exception)]);

            throw new AiUnavailable('provider_error');
        }

        $missing = $this->missingKeys($agent, $response, $requiredKeys);

        if ($missing !== []) {
            $record(AiRun::STATUS_REJECTED_SCHEMA, $response, 'missing: '.implode(',', $missing));

            throw new AiUnavailable('bad_answer');
        }

        $record(AiRun::STATUS_OK, $response);

        return $response;
    }

    /**
     * Só a chamada, sem a AiGate e sem registro: para os comandos (ai:check,
     * ai:compare), que testam o provedor e não são uso de nenhum escopo.
     *
     * @param  list<mixed>  $attachments
     */
    public function send(Agent $agent, Route $route, string $prompt, array $attachments = [], ?int $timeout = null): AgentResponse
    {
        return $agent->prompt(
            $prompt,
            attachments: $attachments,
            provider: $route->provider,
            model: $route->model,
            timeout: $timeout ?? $route->timeout ?? (int) config('ai-kit.timeout'),
        );
    }

    /**
     * As chaves exigidas que a resposta estruturada não trouxe.
     *
     * @param  list<string>  $requiredKeys
     * @return list<string>
     */
    public function missingKeys(Agent $agent, AgentResponse $response, array $requiredKeys): array
    {
        if (! $agent instanceof HasStructuredOutput) {
            return [];
        }

        if (! $response instanceof StructuredAgentResponse) {
            return $requiredKeys;
        }

        return array_values(array_filter($requiredKeys, fn (string $key): bool => ! isset($response[$key])));
    }
}
