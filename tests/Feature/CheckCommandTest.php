<?php

use Callcocam\AiKit\Agents\ConnectionCheck;
use Callcocam\AiKit\AiKit;

it('tests each configured route without recording', function () {
    config(['ai-kit.tasks.statement_read' => ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5']]);
    $seen = [];
    ConnectionCheck::fake(function ($prompt, $attachments, $provider, $model) use (&$seen) {
        $seen[] = $provider->name().':'.$model;

        return ['ok' => true, 'reply' => 'Funcionando.'];
    });

    $this->artisan('ai:check')
        ->expectsOutputToContain('Cloudflare Workers AI (cloudflare) · não treina · não guarda')
        ->expectsOutputToContain('Claude API (Anthropic) (anthropic:claude-haiku-4-5) · não treina · guarda 30 dias')
        ->expectsOutputToContain('Funcionando.')
        ->assertSuccessful();

    expect($seen)->toBe(['cloudflare:@cf/google/gemma-4-26b-a4b-it', 'anthropic:claude-haiku-4-5']);
    AiKit::assertNothingRan();
});

it('fails when a provider has no key or does not answer', function () {
    config(['ai.providers.anthropic.key' => null]);
    ConnectionCheck::fake(fn () => throw new RuntimeException('401'));

    $this->artisan('ai:check', ['--route' => ['anthropic', 'cloudflare']])
        ->expectsOutputToContain('Sem chave em config/ai.php → providers.anthropic.key')
        ->expectsOutputToContain('Falhou: RuntimeException')
        ->assertFailed();
});

it('warns about a provider without declared terms', function () {
    ConnectionCheck::fake([['ok' => true, 'reply' => 'Oi.']]);

    $this->artisan('ai:check', ['--route' => ['openai']])
        ->expectsOutputToContain('conta como "treina e guarda"')
        ->assertSuccessful();
});
