<?php

use Callcocam\AiKit\Models\AiRun;
use Callcocam\AiKit\Policies\AllowAll;
use Callcocam\AiKit\Routing\ConfigRouteResolver;

$env = static function (string $key): ?string {
    $value = env($key);

    return $value === null || $value === '' ? null : (string) $value;
};

return [

    /*
    |--------------------------------------------------------------------------
    | A camada de IA (callcocam/laravel-ai-kit)
    |--------------------------------------------------------------------------
    |
    | Desligada até o servidor ligar (AI_ENABLED). Toda chamada passa pela AiGate
    | (pode chamar?) e pelo AiRunner (chama e registra em `ai_runs`, sem o
    | conteúdo). O pacote não conhece família, congregação nem plano: o que é do
    | projeto entra pela `policy` (Contracts\AiPolicy).
    |
    */

    'enabled' => (bool) env('AI_ENABLED', false),

    // Teto de uma chamada em segundos, quando nem a rota nem quem chama dizem outro.
    'timeout' => (int) env('AI_TIMEOUT', 120),

    // O que é do projeto: interruptor por escopo e cota do mês (Contracts\AiPolicy).
    // A padrão deixa tudo liberado e sem cota.
    'policy' => AllowAll::class,

    // Quem escolhe provedor e modelo de cada chamada (Contracts\RouteResolver). A
    // padrão lê `routes` e `tasks` abaixo; um projeto que guarda isso no banco troca.
    'route_resolver' => ConfigRouteResolver::class,

    /*
     * Rotas por tipo de entrada: `text` (só prompt), `image` (foto, PDF já virado
     * imagem), `document` (arquivo PDF enviado como está) e `audio`. Cada uma diz
     * `provider`, `model` e `timeout`; o que ficar null cai no padrão: o provedor
     * `ai.default` do laravel/ai, o modelo do agente (ou o padrão do provedor) e
     * `timeout` acima. O provedor da rota manda: o atributo #[Provider] do agente
     * não é usado, porque a AiGate precisa saber para onde o conteúdo vai.
     */
    'routes' => [
        'text' => ['provider' => $env('AI_TEXT_PROVIDER'), 'model' => $env('AI_TEXT_MODEL'), 'timeout' => null],
        'image' => ['provider' => $env('AI_IMAGE_PROVIDER'), 'model' => $env('AI_IMAGE_MODEL'), 'timeout' => null],
        'document' => ['provider' => $env('AI_DOCUMENT_PROVIDER'), 'model' => $env('AI_DOCUMENT_MODEL'), 'timeout' => null],
        'audio' => ['provider' => $env('AI_AUDIO_PROVIDER'), 'model' => $env('AI_AUDIO_MODEL'), 'timeout' => null],
    ],

    /*
     * Sobrescrita por tarefa, por cima da rota do tipo de entrada. Ex.:
     *   'statement_read' => ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5'],
     *   'whatsapp_message_read' => ['model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'timeout' => 20],
     * Uma tarefa que troca o provedor sem dizer o modelo não herda o modelo da rota
     * (que é de outro provedor).
     */
    'tasks' => [],

    /*
     * Grupos de tarefas com cota própria. As chamadas de um grupo só contam contra
     * a cota do grupo; as tarefas fora de qualquer grupo formam o grupo `default`.
     * Ex.: 'whatsapp' => ['whatsapp_message_read', 'whatsapp_audio_transcribe'],
     * para a conversa ao vivo não esgotar a leitura de documentos, nem o contrário.
     */
    'groups' => [],

    // Anexo (documento, imagem, áudio) só vai a provedor que também não guarda o
    // conteúdo. Desligado, o provedor que guarda por um prazo (Anthropic: 30 dias)
    // recebe anexos; o que treina com os dados nunca recebe nada.
    'attachments_require_no_retention' => (bool) env('AI_ATTACHMENTS_REQUIRE_NO_RETENTION', false),

    /*
    |--------------------------------------------------------------------------
    | O registro das chamadas
    |--------------------------------------------------------------------------
    |
    | Uma linha por chamada em `table`, com o dono em `scope_column` (team_id,
    | congregation_id…): cada projeto mantém a tabela que já tem, sem migrar dados.
    | `model` pode ser uma subclasse do AiRun do pacote (colunas extras, carimbo do
    | autor). Append-only.
    |
    */

    'runs' => [
        'table' => 'ai_runs',
        'scope_column' => 'team_id',
        'model' => AiRun::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentos (DocumentPreparer)
    |--------------------------------------------------------------------------
    |
    | O driver openai-compatible (Cloudflare) não aceita PDF, só texto e imagem.
    | PDF com texto vai como texto (pdftotext: exato e mais barato que imagem); PDF
    | escaneado tem as primeiras páginas convertidas em JPEG (pdftoppm); foto vai
    | como está. Páginas e texto têm teto, para um documento longo não esgotar a
    | cota grátis do dia. Precisa de poppler-utils no servidor.
    |
    */

    'documents' => [
        'max_pages' => 5,
        // Teto de imagens somando todos os arquivos de uma chamada (DocumentPreparer::fromMany).
        'max_images' => 8,
        'max_text_chars' => 60_000,
        // Abaixo disso o PDF é tratado como escaneado (só cabeçalho ou nada de texto).
        'min_text_chars' => 80,
        'dpi' => 110,
    ],

    /*
    |--------------------------------------------------------------------------
    | Provedores e termos
    |--------------------------------------------------------------------------
    |
    | O que cada provedor faz com o conteúdo, conferido na documentação dele em
    | `checked_on` (confira de novo antes de mudar: muda com frequência). Provedor
    | que não está aqui conta como um que treina e guarda: nunca recebe nada.
    |
    | `vision`/`audio`: lê imagem / ouve áudio. `rates`: preço por milhão de tokens
    | de cada modelo, na unidade de `currency` (os neurons da Cloudflare, US$ da
    | Anthropic). `daily_allowance`: cota grátis do dia, da conta inteira do
    | provedor, na mesma unidade (zera às 00:00 UTC).
    |
    */

    'providers' => [
        // developers.cloudflare.com/workers-ai/platform/data-usage e /pricing (13/11/2026):
        // não treina nem guarda o conteúdo; 10.000 neurons grátis por dia, depois
        // US$ 0,011 por 1.000 neurons. A documentação não diz a região.
        'cloudflare' => [
            'label' => 'Cloudflare Workers AI',
            'free' => true,
            'trains_on_input' => false,
            'retains_input' => false,
            'retention_days' => 0,
            'region' => null,
            'vision' => true,
            // Transcrição (Whisper) na própria Cloudflare; no chat, só os modelos com visão leem imagem.
            'audio' => true,
            'checked_on' => '2026-11-13',
            'currency' => 'neurons',
            'daily_allowance' => 10_000,
            'rates' => [
                '@cf/google/gemma-4-26b-a4b-it' => ['input' => 9091, 'output' => 27273],
                '@cf/meta/llama-3.3-70b-instruct-fp8-fast' => ['input' => 26668, 'output' => 204805],
            ],
        ],

        // privacy.claude.com e platform.claude.com/docs (13/11/2026): a API não treina
        // com entradas e saídas; guarda por 30 dias (até 2 anos se violar a política
        // de uso). Região de processamento `global` por padrão (`inference_geo: us`
        // custa 1,1x e não existe no Haiku 4.5). Não ouve áudio.
        'anthropic' => [
            'label' => 'Claude API (Anthropic)',
            'free' => false,
            'trains_on_input' => false,
            'retains_input' => true,
            'retention_days' => 30,
            'region' => 'global',
            'vision' => true,
            'audio' => false,
            'checked_on' => '2026-11-13',
            'currency' => 'USD',
            'rates' => [
                'claude-haiku-4-5' => ['input' => 1, 'output' => 5],
                'claude-sonnet-5' => ['input' => 2, 'output' => 10],
                'claude-opus-5-5' => ['input' => 4, 'output' => 20],
            ],
        ],
    ],

];
