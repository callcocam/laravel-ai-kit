# Changelog

Todas as mudanças de `callcocam/laravel-ai-kit` ficam neste arquivo.

## [0.1.1] - 2026-11-13

### Fixed
- **`ProviderTerms::cost()` reconhece o modelo datado que a Anthropic devolve.**
  Pedido com `claude-haiku-4-5`, a resposta trazia `claude-haiku-4-5-20251001`
  (o Sonnet volta sem data); sem bater com a chave de `rates`, o custo saía
  `null`. Agora cai para a chave configurada mais específica da qual o modelo é
  uma versão datada (o sufixo começa com dígito); um sufixo com letra
  (`-pro`, `-mini`) continua contando como outro modelo, sem preço.

## [0.1.0] - 2026-11-13

Primeira versão, extraída da camada de IA do No Azul e do Contas da Congregação.

### Added
- **Provedores e termos** (`config/ai-kit.php → providers`): treina, guarda (e por quantos dias),
  região, imagem, áudio, data da conferência, preços por modelo e cota diária. Provedor desconhecido
  conta como "treina e guarda". Cloudflare Workers AI e Anthropic conferidos em 13/11/2026.
- **Rotas** por tipo de entrada (`text`, `image`, `document`, `audio`) e por tarefa, com o padrão
  no `ai.default` do `laravel/ai`. `Contracts\RouteResolver` para quem guarda as rotas no banco.
- **`AiGate`** com os motivos `server_off`, `not_configured`, `scope_off`, `trains_on_data`, `budget`,
  `keeps_data` (opcional), `no_vision`, `no_audio`, e a política do projeto por `Contracts\AiPolicy`
  (`enabled`, `monthlyBudget`) e `Contracts\RestrictsTasks` (`allows`, com motivo próprio).
  Cotas por grupo de tarefas (`groups`).
- **`AiRunner::run()`**: porta → rota → chamada → registro em `ai_runs` sem conteúdo. Uma resposta
  sem campo exigido vira `rejected_schema` + `bad_answer`; a falha do provedor vira `failed` +
  `provider_error`. `send()` chama sem porta nem registro, para os comandos.
- **`ai_runs`** com tabela, coluna do dono e model configuráveis (`runs.*`), append-only; migration
  só publicável (`ai-kit-migrations`).
- **`DocumentPreparer`**: PDF com texto → texto (`pdftotext`), PDF escaneado → páginas em JPEG
  (`pdftoppm`), foto como está; `fromMany()` com teto de imagens somado e `used`/`skipped`.
- **`AiUsage`**: chamadas do mês por escopo e grupo, custo estimado pelas `rates`, uso do dia contra
  a cota diária.
- **Comandos** `ai:check`, `ai:compare` (acerto contra gabarito, custo e tempo por provedor/modelo,
  CSV) e `ai:usage`.
- **Testes**: `AiKit::fake()`, `FakePolicy`, `AiKit::assertRan()` e `assertNothingRan()`.
- Textos dos motivos em pt_BR e en (`ai-kit::unavailable.*`).
