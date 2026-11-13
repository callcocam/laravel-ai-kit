<?php

use Callcocam\AiKit\Providers\ProviderTerms;

beforeEach(function () {
    config(['ai-kit.providers.anthropic.rates' => [
        'claude-haiku-4-5' => ['input' => 1, 'output' => 5],
        'claude-sonnet-5' => ['input' => 2, 'output' => 10],
    ]]);
});

it('costs by the exact model name', function () {
    expect(ProviderTerms::for('anthropic')->cost('claude-sonnet-5', 1_000_000, 1_000_000))->toBe(12.0);
});

it('falls back to the configured rate when the provider answers with a dated version of it', function () {
    // A Anthropic às vezes devolve "claude-haiku-4-5-20251001" para um pedido de "claude-haiku-4-5".
    expect(ProviderTerms::for('anthropic')->cost('claude-haiku-4-5-20251001', 1_000_000, 1_000_000))->toBe(6.0);
});

it('prefers the longest matching rate when more than one is a prefix', function () {
    config(['ai-kit.providers.anthropic.rates' => [
        'claude-haiku-4' => ['input' => 1, 'output' => 1],
        'claude-haiku-4-5' => ['input' => 9, 'output' => 9],
    ]]);

    // "claude-haiku-4" também bate (o "5" depois do traço é dígito), mas "claude-haiku-4-5" é mais específico.
    expect(ProviderTerms::for('anthropic')->cost('claude-haiku-4-5-20251001', 1_000_000, 1_000_000))->toBe(18.0);
});

it('does not match an unrelated model that merely shares a prefix word', function () {
    expect(ProviderTerms::for('anthropic')->cost('claude-haiku-4-5-pro', 1_000_000, 1_000_000))->toBeNull()
        ->and(ProviderTerms::for('anthropic')->cost('claude-haiku-45', 1_000_000, 1_000_000))->toBeNull();
});

it('gives null without a model or without any matching rate', function () {
    expect(ProviderTerms::for('anthropic')->cost(null, 1_000_000, 1_000_000))->toBeNull()
        ->and(ProviderTerms::for('anthropic')->cost('gpt-5', 1_000_000, 1_000_000))->toBeNull();
});
