# callcocam/laravel-ai-kit

A camada de IA comum aos projetos, em cima do [`laravel/ai`](https://github.com/laravel/ai). Cada
projeto reinventava a mesma coisa: saber se pode chamar, escolher o provedor, preparar o documento,
chamar, registrar e medir. Isso fica aqui, num modelo fixo. O projeto só diz **o que pedir** (os
agentes) e **o que fazer com a resposta**.

- PHP ^8.3, Laravel ^12 | ^13, `laravel/ai` ^1.3
- `poppler-utils` no servidor (`pdftotext` e `pdftoppm`) para ler PDF

## Instalação

O repositório é privado. Adicione o VCS no `composer.json` do projeto, como o pacote do WhatsApp:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/callcocam/laravel-ai-kit" }
]
```

```bash
composer require callcocam/laravel-ai-kit:^0.1
php artisan vendor:publish --tag=ai-kit-config
```

O repositório é privado: o Composer do projeto (local, CI ou build de produção) precisa de um
token do GitHub pra baixar. Criar um, **um por projeto que for instalar**:

1. **[github.com/settings/personal-access-tokens/new](https://github.com/settings/personal-access-tokens/new)**
   (fine-grained, não o clássico).
2. Resource owner: `callcocam`. Repository access: "Only select repositories" → `laravel-ai-kit`.
3. Permissions → Repository permissions → **Contents: Read-only** (Metadata: Read-only vem junto,
   obrigatório). Nenhuma outra permissão.
4. Expiração: sem problema deixar **sem expirar** (token só de leitura deste repo, usado só por
   instalação/CI; o risco de vazamento é baixo pelo escopo mínimo).
5. Gerar e usar como `COMPOSER_AUTH='{"github-oauth":{"github.com":"<token>"}}'` no `composer
   require`/`install`/`update`. Num build Docker, passar por `--mount=type=secret` do BuildKit,
   nunca em `ARG`/`ENV` da imagem (fica gravado na history). Num CI, guardar como secret do
   repositório (ex. `gh secret set COMPOSER_AUTH_TOKEN --repo <owner>/<projeto>`) e montar o JSON
   acima no passo que roda o Composer.

Migrando um projeto que já tem a própria camada de IA (um `AiGate`/`AiRunner` caseiro)? Veja
[docs/migrar-um-projeto.md](docs/migrar-um-projeto.md): o passo a passo e o que cada projeto que
já usa (ou vai usar) o pacote precisou mexer.

A tabela `ai_runs`:

- **Projeto que já tem a tabela** (No Azul, Contas): não publique a migration. Aponte
  `runs.table` e `runs.scope_column` para o que já existe. Nenhum dado é migrado.
- **Projeto novo**: rode `php artisan vendor:publish --tag=ai-kit-migrations`, ajuste a coluna do dono
  (troque por `foreignUlid(...)->constrained()` se quiser a chave estrangeira) e rode `migrate`.

## Chave de cada provedor de IA

Cada provedor tem a própria chave, configurada no `config/ai.php` **do projeto** (não deste
pacote — isso é do `laravel/ai`), por variável de ambiente. O pacote só decide **para qual**
provedor a chamada vai (`ai-kit.routes`/`tasks`); quem guarda a chave é o `config/ai.php` de
sempre.

| Provedor | Onde criar a chave | Variáveis no `.env` |
|---|---|---|
| Cloudflare Workers AI | [dash.cloudflare.com](https://dash.cloudflare.com) → a conta → **Workers AI** (o Account ID aparece nessa mesma tela) → **Use REST API** → **Create a Workers AI API Token** (permissões "Workers AI - Read" + "Workers AI - Edit") | `CLOUDFLARE_ACCOUNT_ID`, `CLOUDFLARE_AI_TOKEN` |
| Anthropic (Claude) | [platform.claude.com/settings/workspaces](https://platform.claude.com/settings/workspaces) → **Create workspace** (isola o gasto do projeto; o workspace **Default** não aceita limite de gasto) → clique no workspace criado → **Spend limits** pra definir o teto mensal → tela "Criar chave de API" com Escopo = esse workspace (não "Organização", que vale pra conta inteira) | `ANTHROPIC_API_KEY` |
| Google Gemini | [aistudio.google.com/apikey](https://aistudio.google.com/apikey) (ou o Google Cloud Console, pra uma chave de projeto/faturamento) | `GEMINI_API_KEY` |
| OpenAI | [platform.openai.com/api-keys](https://platform.openai.com/api-keys) | `OPENAI_API_KEY` |

Depois de criar, declare o provedor em `ai-kit.providers` (Cloudflare e Anthropic já vêm prontos;
Gemini e OpenAI ficam como referência — copie os termos da tabela "Provedores e termos" mais
abaixo, conferindo de novo na documentação oficial) e aponte a rota pra ele
(`ai-kit.routes`/`tasks`, veja "Rotas" mais abaixo).

## O que é do pacote e o que fica no projeto

| Pacote | Projeto |
|---|---|
| Termos e preços dos provedores (`providers`) | Os agentes (`instructions()` + `schema()`) |
| Rotas: tipo de entrada e tarefa → provedor, modelo e teto | Os nomes das tarefas e os grupos de cota |
| `AiGate`: os motivos genéricos para não chamar | A `AiPolicy`: interruptor por escopo, cota por plano, restrição por tarefa |
| `AiRunner`: chama e registra em `ai_runs`, sem conteúdo | O aceite e a LGPD (antes de chamar) |
| `DocumentPreparer`: PDF → texto ou imagens | As regras de domínio (texto ancorado, passagens do manual…) |
| `AiUsage`: chamadas do mês, custo, cota do dia | As telas e os textos dos motivos (`lang/vendor/ai-kit`) |
| `ai:check`, `ai:compare`, `ai:usage`; `AiKit::fake()` | O que fazer com a resposta |

O pacote não conhece família, congregação, plano nem LGPD. O **escopo** é o dono da chamada (o id
da equipe, o model da congregação…), passado do jeito que o projeto quiser. Ele vai para a política
como veio e é gravado em `runs.scope_column`.

## Uso

### Um agente

Os agentes são do `laravel/ai`, sem nada de especial:

```php
namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class ReceiptReader implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Você lê comprovantes de pagamento brasileiros. Devolva só o que está no documento.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->string()->required(),
            'payee' => $schema->string()->required(),
        ];
    }
}
```

### Chamar

Toda chamada passa pelo `AiRunner`:

```php
use Callcocam\AiKit\AiRunner;
use Callcocam\AiKit\Documents\DocumentPreparer;
use Callcocam\AiKit\Exceptions\AiUnavailable;

$document = app(DocumentPreparer::class)->from($contents, $mime);   // ['text' => …, 'images' => […]]

try {
    $answer = app(AiRunner::class)->run(
        new ReceiptReader,
        task: 'receipt_read',
        prompt: trim("Leia o comprovante.\n\n".($document['text'] ?? '')),
        scope: $team->id,                 // ou o model
        user: $user,                      // id, model ou null
        attachments: $document['images'],
        inputRefs: ['import' => $import->id],   // ids, nunca conteúdo
        requiredKeys: ['amount', 'payee'],
    );
} catch (AiUnavailable $e) {
    return back()->with('error', $e->userMessage());   // $e->reason: 'budget', 'scope_off'…
}
```

O runner, em ordem:

1. Descobre o tipo de entrada pelos anexos.
2. Pergunta à `AiGate` se pode chamar.
3. Chama o agente no provedor e no modelo da rota.
4. Grava uma linha em `ai_runs` com o hash do prompt, os ids, os tokens, a duração e o status
   (`ok`, `failed`, `rejected_schema`). O texto do prompt nunca é gravado.

Se a resposta estruturada vier sem um campo de `requiredKeys`, a linha fica como `rejected_schema` e
o runner lança `AiUnavailable('bad_answer')`. Se o provedor falhar, a linha fica como `failed` e o
runner lança `provider_error`. O log recebe só a classe da exceção, porque a mensagem do provedor
pode ecoar o documento. `$runner->lastRun` guarda o registro, para o projeto ligar o resultado a ele.

### Política do projeto

```php
namespace App\Support\Ai;

use App\Models\Team;
use App\Support\Plans\Entitlements;
use Callcocam\AiKit\Contracts\AiPolicy;
use Callcocam\AiKit\Contracts\RestrictsTasks;
use Callcocam\AiKit\Support\TaskGroups;

class NoAzulAiPolicy implements AiPolicy, RestrictsTasks
{
    public function __construct(private Entitlements $entitlements) {}

    // A família desligou a IA em Configurações → Inteligência artificial.
    public function enabled(mixed $scope): bool
    {
        return (bool) Team::query()->whereKey($scope)->value('ai_enabled');
    }

    // A cota do plano no grupo da tarefa (WhatsApp ou documentos); null = sem limite.
    public function monthlyBudget(mixed $scope, ?string $task): ?int
    {
        $limit = TaskGroups::of($task) === 'whatsapp' ? 'ai_whatsapp' : 'ai_documents';

        return $this->entitlements->limit($scope, $limit);
    }

    // Opcional: recusar uma tarefa com um motivo do projeto. É conferido antes de tudo.
    public function allows(mixed $scope, string $task): true|string
    {
        return in_array($task, ['price_tag_read', 'purchase_receipt_read'], true) && ! $this->entitlements->allows($scope, 'purchase_photos')
            ? 'plan'
            : true;
    }
}
```

```php
// config/ai-kit.php
'policy' => App\Support\Ai\NoAzulAiPolicy::class,
'groups' => ['whatsapp' => ['whatsapp_message_read', 'whatsapp_audio_transcribe']],
'runs' => ['table' => 'ai_runs', 'scope_column' => 'team_id', 'model' => Callcocam\AiKit\Models\AiRun::class],
```

A política padrão (`AllowAll`) deixa tudo ligado e sem cota.

### A porta (`AiGate`)

`blockedReason($scope, $task, $input)` devolve o motivo, ou null quando pode chamar. `ensure()`
lança `AiUnavailable($reason)`. As conferências seguem esta ordem:

| Motivo | Quando |
|---|---|
| *(do projeto)* | `RestrictsTasks::allows()` recusou (ex.: `plan`) |
| `server_off` | `AI_ENABLED` desligado |
| `not_configured` | falta a chave do provedor da rota (`ai.providers.<p>.key`) |
| `scope_off` | `AiPolicy::enabled()` é false |
| `trains_on_data` | o provedor da rota treina com o conteúdo, ou não está em `providers` |
| `budget` | as chamadas do mês no grupo da tarefa chegaram a `monthlyBudget()` |
| `keeps_data` | há anexo, o provedor guarda o conteúdo e `attachments_require_no_retention` está ligado |
| `no_vision` / `no_audio` | o provedor não lê imagem / não ouve áudio |
| `provider_error` / `bad_answer` | (do runner) o provedor falhou / a resposta veio incompleta |

Os textos ficam em `ai-kit::unavailable.<motivo>` (pt_BR e en). Para trocá-los, rode
`vendor:publish --tag=ai-kit-lang` e escreva o texto de cada motivo do projeto, como `plan`.

`terms($task)`, `provider($task)` e `route($task)` dizem para onde a tarefa vai, para a tela de
configurações mostrar ("Documentos: Claude · WhatsApp: Cloudflare").

### Rotas

A rota vem do tipo de entrada, que o runner descobre pelos anexos:

| Tipo | Quando |
|---|---|
| `text` | sem anexos |
| `image` | imagens, inclusive o PDF que o `DocumentPreparer` virou imagens |
| `document` | algum `Laravel\Ai\Files\Document` (PDF enviado como está) |
| `audio` | algum `Laravel\Ai\Files\Audio` |

A configuração da tarefa fica por cima da rota do tipo:

```php
'routes' => [
    'image' => ['provider' => env('AI_IMAGE_PROVIDER'), 'model' => env('AI_IMAGE_MODEL'), 'timeout' => null],
    // text, document, audio…
],
'tasks' => [
    'statement_read' => ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5'],
    'whatsapp_message_read' => ['model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'timeout' => 20],
],
```

- O que fica sem valor cai no padrão: o provedor é o `ai.default` do `laravel/ai`; o modelo é o do
  agente (`model()` ou `#[Model]`) ou, sem ele, o padrão do provedor; o teto é o `ai-kit.timeout`.
- O provedor da rota manda. O `#[Provider]` do agente não é usado, porque a porta precisa saber
  para onde o conteúdo vai.
- Uma tarefa que troca o provedor sem dizer o modelo não herda o modelo da rota, que é de outro
  provedor.
- Para guardar as rotas no banco (Plannerate), implemente `Contracts\RouteResolver` e aponte
  `ai-kit.route_resolver` para a sua classe.

### Registro (`ai_runs`)

- `runs.table` e `runs.scope_column` mantêm a tabela de cada projeto (`team_id`, `congregation_id`…).
- O registro é append-only: editar ou apagar uma linha lança `LogicException`.
- `runs.model` aceita uma subclasse de `Callcocam\AiKit\Models\AiRun` para colunas extras, como um
  carimbo do autor preenchido no `creating` (o `user_stamp` do Contas).
- `AiRun::query()->ownedBy($scope)` filtra as linhas de um escopo.

### Documentos

`DocumentPreparer` faz uma coisa diferente para cada tipo de arquivo:

| Arquivo | Vai como |
|---|---|
| PDF com texto | texto, pelo `pdftotext`: exato e mais barato que imagem |
| PDF escaneado | as primeiras páginas em JPEG, pelo `pdftoppm` (`max_pages`, 110 dpi) |
| Foto (JPEG, PNG, WebP) | a própria imagem |
| `text/*` | o próprio texto |

O driver openai-compatible (Cloudflare) não aceita PDF, por isso o documento chega sempre como texto
ou imagem.

```php
$preparer->from($contents, $mime);                     // ['text' => ?string, 'images' => list<Base64Image>]
$preparer->from($contents, $mime, text: false);        // o PDF sempre como imagens
$preparer->fromMany([                                  // vários arquivos, com teto somado (max_images)
    $attachment->id => ['mime' => $attachment->mime, 'contents' => fn () => Storage::get($attachment->path)],
], maxPagesPerFile: 3, text: false);                   // + 'used' e 'skipped' (ids)
DocumentPreparer::available();                         // o poppler está instalado?
```

Os tetos de páginas, imagens e caracteres ficam em `ai-kit.documents`. Sem o poppler, todo PDF sai
vazio, um aviso vai para o log e o `ai:check` avisa.

### Uso e custo

```php
$usage = app(Callcocam\AiKit\AiUsage::class);
$usage->runsThisMonth($scope);                       // todas
$usage->runsThisMonth($scope, task: 'receipt_read'); // só o grupo da tarefa
$usage->month($scope);     // por grupo e provedor/modelo: chamadas, tokens, custo estimado e moeda
$usage->daily();           // os últimos 14 dias contra a daily_allowance (dias UTC)
$usage->summary($scope, 'receipt_read');   // daily + ['runs' => …, 'limit' => cota da política]
```

O custo é estimado pelas `rates` do provedor, por milhão de tokens e na unidade de `currency`
(neurons na Cloudflare, US$ na Anthropic). Modelo sem preço declarado tem custo `null`.

## Comandos

| Comando | O que faz |
|---|---|
| `ai:check [--route=anthropic:claude-haiku-4-5]` | Testa cada provedor/modelo das rotas (ou os indicados) com uma pergunta curta. Mostra os termos e avisa se falta chave ou poppler. Funciona com `AI_ENABLED` desligado e não grava em `ai_runs`. |
| `ai:compare {pasta} --agent= --providers=cloudflare,anthropic:claude-haiku-4-5` | Roda o mesmo agente, com o mesmo preparo de documento, em vários provedores, contra um gabarito, e mede acerto campo a campo, tokens, custo e tempo. Detalhes abaixo. |
| `ai:usage [escopo] [--provider=] [--days=14]` | O mês do escopo por grupo e provedor/modelo, com custo, e os dias contra a cota diária. |

Como o `ai:compare` funciona:

- **Gabarito.** Cada documento tem um ao lado: `extrato.pdf.esperado.json` ou
  `extrato.esperado.json`. Nele, `_agent` e `_prompt` valem só para aquele arquivo, para uma pasta
  misturar tipos de documento.
- **Prompt.** Um agente que implementa `Contracts\ComparesDocuments` monta o próprio prompt. Os
  outros recebem o `--prompt` seguido do texto do PDF.
- **Comparação.** Número compara pelo valor (`"1.234,56"` = `1234.56`). Texto compara sem diferença
  de maiúscula nem de espaço sobrando.
- **Saída.** Uma tabela por arquivo e um resumo por provedor. O CSV fica na pasta, ou em `--csv`.
- **Custo.** As chamadas são reais: o comando pede confirmação (pule com `--force`). Use
  `--dry-run` para só contar as chamadas. Não passa pela porta e não grava em `ai_runs`.

## Testes do projeto

O provedor se finge com o fake do `laravel/ai`. O `AiKit::fake()` liga a camada sem chave de
verdade:

```php
use Callcocam\AiKit\AiKit;
use Callcocam\AiKit\Testing\FakePolicy;

AiKit::fake();   // AI_ENABLED ligado, toda chave vazia preenchida, política intacta
AiKit::fake(FakePolicy::make()->budget(3)->budget(10, 'whatsapp')->off($otherTeam)->deny('price_tag_read', 'plan'));

ReceiptReader::fake([['amount' => '42,90', 'payee' => 'Padaria']]);
// … chama o código do projeto …
AiKit::assertRan('receipt_read', 'ok');
AiKit::assertNothingRan();
```

## Provedores e termos

Conferidos na documentação de cada provedor em **13/11/2026**. Esses termos mudam com frequência:
confira de novo antes de trocar de provedor e atualize `checked_on`. Um provedor fora de
`ai-kit.providers` conta como "treina e guarda" e nunca recebe nada. O config traz Cloudflare e
Anthropic; Gemini e OpenAI ficam aqui como referência para os projetos que os usam.

| Provedor | Treina com o conteúdo? | Guarda? | Região | Imagem / áudio | Preço (por milhão de tokens) | Fonte |
|---|---|---|---|---|---|---|
| Cloudflare Workers AI | Não | Não (só enquanto processa) | não informada | Gemma 4 lê imagem; Llama 3.3 só texto / Whisper para transcrição | 10.000 neurons grátis por dia (zera 00:00 UTC), depois US$ 0,011 por 1.000 neurons. Gemma 4 26B: 9.091 / 27.273 neurons (≈ US$ 0,10 / 0,30). Llama 3.3 70B fast: 26.668 / 204.805 neurons | [data usage](https://developers.cloudflare.com/workers-ai/platform/data-usage/), [pricing](https://developers.cloudflare.com/workers-ai/platform/pricing/) |
| Anthropic (Claude API) | Não (só com feedback ou consentimento explícito) | 30 dias; até 2 anos se violar a política de uso. ZDR por organização, via vendas | `global` por padrão; `inference_geo: us` custa 1,1x e não existe no Haiku 4.5 | Lê imagem / não ouve áudio | Haiku 4.5: US$ 1 / 5. Sonnet 5: US$ 2 / 10. Opus 5.5: US$ 4 / 20 | [treino](https://privacy.claude.com/en/articles/7996868-is-my-data-used-for-model-training), [retenção](https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data), [região](https://platform.claude.com/docs/en/manage-claude/data-residency), [preços](https://platform.claude.com/docs/en/about-claude/pricing) |
| Google Gemini API | **Grátis: sim** (inclusive revisão humana). Pago: não | 55 dias, para abuso. Sem ZDR na API (só no Vertex AI) | não informada | Lê imagem, áudio e PDF | gemini-2.5-flash: US$ 0,30 / 2,50, e a partir de 01/12/2026 passa a responder como gemini-3.5-flash-lite. gemini-3.8-flash: US$ 0,75 / 3,75 até 31/12/2026, depois US$ 1,50 / 7,50 | [termos](https://aistudio.google.com/docs/terms), [abuso](https://aistudio.google.com/docs/usage-policies), [preços](https://aistudio.google.com/docs/pricing) |
| OpenAI API | Não (salvo opt-in) | 30 dias, para abuso. ZDR com aprovação | residência de dados com aprovação (EUA, Europa e outras) | gpt-5 e gpt-5-mini leem imagem / áudio só pelos modelos de transcrição | gpt-5: US$ 1,25 / 10. gpt-5-mini: US$ 0,25 / 2 (os dois descontinuados). whisper-1: US$ 0,006 por minuto | [seus dados](https://developers.openai.com/api/docs/guides/your-data), [preços](https://developers.openai.com/api/docs/pricing) |

Para usar Gemini ou OpenAI, declare o provedor em `ai-kit.providers` com os termos acima. O Gemini
grátis precisa ficar com `trains_on_input: true`, e a porta não deixa nada ir para ele.

## Desenvolvimento

```bash
composer test            # Pint, PHPStan (nível 6) e Pest
vendor/bin/testbench ai:check --route=cloudflare
```

Sem PHP no host, use a imagem de um dos projetos:
`docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD:/app" -w /app noazul-php composer test`.
Os testes de documento precisam do poppler e são pulados quando ele falta.
