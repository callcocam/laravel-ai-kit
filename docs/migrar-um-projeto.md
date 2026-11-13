# Migrar um projeto existente para o pacote

Guia para um projeto que já tem a própria camada de IA (um `AiGate`/`AiRunner` caseiro, ou
qualquer outra coisa em cima do `laravel/ai`) passar a usar este pacote. Comportamento igual ao
de antes é a régua: mesmo provedor, mesmas cotas, mesmas mensagens — só muda de onde vem o
código. Testes verdes **sem mudar expectativas** é o que prova isso.

O primeiro projeto a migrar foi o [No Azul](https://github.com/callcocam/noazul) — veja o PR dele
(specs 20 e 21 em `docs/plans/`) como referência de como ficou: `AiGate`/`AiRunner` do projeto
viraram classes finas que delegam ao pacote, mantendo a API que o resto do app já usava (zero
mudança nos outros 20 arquivos que chamavam essas classes).

## Passo a passo

1. **Instalar**: repositório VCS no `composer.json` (é privado) + `composer require
   callcocam/laravel-ai-kit`. Veja o `composer.json` do No Azul como modelo exato
   (`repositories` + `require`).
2. **CI e build de produção**: se o projeto tiver GitHub Actions ou build de imagem Docker que
   rode `composer install`, vai precisar de um `COMPOSER_AUTH` com um token que leia este
   repositório (ele é privado). O No Azul resolveu com um secret `COMPOSER_AUTH_TOKEN` (PAT
   fine-grained, só leitura deste repo) — veja `.github/workflows/tests.yml`,
   `.github/workflows/lint.yml`, `Dockerfile.prod` (secret do BuildKit, nunca em ARG/ENV) e
   `.github/workflows/vps-build-push.yml` do No Azul.
3. **`config/ai-kit.php`**: publicar (`vendor:publish --tag=ai-kit-config`) e preencher
   `runs.table`/`runs.scope_column` com a tabela que o projeto **já tem** (sem migrar dados) —
   ou, se o projeto ainda não registra nada, publicar a migration do pacote
   (`--tag=ai-kit-migrations`) com o escopo que fizer sentido lá. Os termos de cada provedor
   (`providers.*`) **conferidos na documentação oficial na data** — não copie os do README sem
   checar de novo, eles mudam com frequência.
4. **Uma `AiPolicy` do projeto** (`Contracts\AiPolicy`): o que o pacote não pode saber — cota por
   plano/organização, interruptor por conta. Opcionalmente `Contracts\RestrictsTasks` para um
   motivo de recusa que não é genérico (ex.: "esse recurso não está no seu plano").
5. **Trocar `AiGate`/`AiRunner` do projeto pelos do pacote**, ou deixar classes finas que
   delegam (reduz o diff se o projeto tem muitos call sites, como o No Azul fez) — qualquer uma
   das duas é válida, ver `README.md` → "Uso".
6. **`ai:check` do pacote** substitui o comando caseiro do projeto, se houver um.
7. Rodar `php artisan test` inteiro, PHPStan e lint do projeto. Em produção, depois do deploy:
   `php artisan ai:check` e uma chamada real de cada funcionalidade gravando em `ai_runs`.

## Projetos a migrar (decidido em 13/11/2026)

Um chat por projeto, nesta ordem (do mais parecido com o No Azul ao mais diferente):

### A. Contas da Congregação (`~/projects/coordena-contas-congregacao`)
- Hoje: `App\Support\Ai\{AiGate, AiRunner, AiUsage, DocumentPages}`, `config/coordena_ai.php`,
  `config/ai.php` (`default_for_images => gemini`, `default_for_audio/transcription => openai`),
  `ai_runs` com `congregation_id` e `user_stamp`.
- Vai para o pacote: gate, runner, uso/custo, `DocumentPages` (→ `DocumentPreparer`), termos dos
  provedores, `ai:check`. `runs.scope_column = congregation_id`; `user_stamp` fica como campo
  extra do projeto (`runs.model` aceita uma subclasse do `AiRun` do pacote para isso).
- Fica no projeto: `GroundedText`, `References`, `ReferenceLibrary`, `ReferenceSources` (o
  runner do projeto exige as passagens do manual antes do prompt: manter como um passo do projeto
  em volta do runner do pacote) e os agentes (`AuditItemReviewer`, `DepositSlipExtractor`, …).

### B. Coordena (`~/projects/coordena`)
- Hoje: `App\Services\Ai\AiAssistant` monta o prompt de `config/ai_assist.php`
  (purposes/roles/actions), chama o `laravel/ai` e higieniza a saída (placeholders, teto);
  agente `MessageAssistant`; provedor Gemini.
- Vai para o pacote: a chamada (runner), o registro em `ai_runs` (o Coordena ainda não registra:
  criar a tabela pelo pacote, com o escopo que fizer sentido lá), termos do Gemini, `ai:check`.
- Fica no projeto: a composição do prompt e a higienização (`AiAssistant` passa a usar o runner).
  As cotas por usuário/time/global (`ai_assist.limits`) também ficam no projeto — dá pra virar
  uma `AiPolicy` própria ou continuar como está, à critério de quem migrar.

### C. Plannerate (`~/projects/plannerate-v1`)
- Hoje: `laravel/ai` **0.x-dev** (subir para ^1.3 primeiro, num passo próprio, com testes);
  `App\Services\Ai\AiFeatureSettings` (provedor/modelo/ativo por funcionalidade, com sobrescrita
  no banco), `config/ai.php → features`; agentes `GenerationConfigAdvisor`, `JbpDiagnosisWriter`,
  `ProductDimensionResearcher`; `GeminiProductImageEditor` e `CloudflareService` (edição de imagem
  e infraestrutura: **não** entram no pacote).
- Vai para o pacote: rotas por tarefa com um `RouteResolver` do projeto que lê a tabela de
  configurações (o `AiFeatureSettings` vira isso), runner, registro, termos, `ai:check`.

## Verificação (em cada projeto)
`php artisan test` verde sem mudar expectativas; `ai:check` em produção depois do deploy; uma
chamada real de cada funcionalidade gravando em `ai_runs`.
