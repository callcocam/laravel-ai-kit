<?php

use Callcocam\AiKit\AiGate;
use Callcocam\AiKit\AiKit;
use Callcocam\AiKit\Contracts\AiPolicy;
use Callcocam\AiKit\Exceptions\AiUnavailable;
use Callcocam\AiKit\Models\AiRun;
use Callcocam\AiKit\Policies\AllowAll;
use Callcocam\AiKit\Routing\Input;
use Callcocam\AiKit\Testing\FakePolicy;

function aiGate(): AiGate
{
    return app(AiGate::class);
}

function pastRun(string $scope, string $task, array $attributes = []): AiRun
{
    return AiRun::query()->create([
        'team_id' => $scope, 'task' => $task, 'provider' => 'cloudflare', 'model' => '@cf/google/gemma-4-26b-a4b-it',
        'prompt_hash' => str_repeat('a', 64), 'status' => 'ok', 'created_at' => now(), ...$attributes,
    ]);
}

it('lets everything through with the default policy', function () {
    expect(app(AiPolicy::class))->toBeInstanceOf(AllowAll::class)
        ->and(aiGate()->blockedReason('team-1', 'statement_read', Input::Image))->toBeNull();
});

it('blocks with the reason', function (Closure $arrange, string $reason, Input $input = Input::Text) {
    $arrange();

    expect(aiGate()->blockedReason('team-1', 'statement_read', $input))->toBe($reason);
    expect(fn () => aiGate()->ensure('team-1', 'statement_read', $input))->toThrow(fn (AiUnavailable $e) => expect($e->reason)->toBe($reason));
})->with([
    'server off' => [fn () => config(['ai-kit.enabled' => false]), 'server_off'],
    'no key' => [fn () => config(['ai.providers.cloudflare.key' => null]), 'not_configured'],
    'scope off' => [fn () => AiKit::fake(FakePolicy::make()->off('team-1')), 'scope_off'],
    'trains on data' => [fn () => config(['ai-kit.providers.cloudflare.trains_on_input' => true]), 'trains_on_data'],
    'unknown provider counts as training' => [fn () => config(['ai-kit.tasks.statement_read.provider' => 'openai']), 'trains_on_data'],
    'budget' => [function () {
        AiKit::fake(FakePolicy::make()->budget(1));
        pastRun('team-1', 'receipt_read');
    }, 'budget'],
    'keeps data' => [fn () => config(['ai-kit.attachments_require_no_retention' => true, 'ai-kit.routes.image.provider' => 'anthropic']), 'keeps_data', Input::Image],
    'no vision' => [fn () => config(['ai-kit.providers.cloudflare.vision' => false]), 'no_vision', Input::Image],
    'no audio' => [fn () => config(['ai-kit.routes.audio.provider' => 'anthropic']), 'no_audio', Input::Audio],
    'project reason' => [fn () => AiKit::fake(FakePolicy::make()->deny('statement_read', 'plan')), 'plan'],
]);

it('puts the project reason before the server switch', function () {
    AiKit::fake(FakePolicy::make()->deny('price_tag_read', 'plan'));
    config(['ai-kit.enabled' => false]);

    expect(aiGate()->blockedReason('team-1', 'price_tag_read'))->toBe('plan')
        ->and(aiGate()->blockedReason('team-1', 'statement_read'))->toBe('server_off');
});

it('checks the key and the terms of the provider of the task', function () {
    config([
        'ai-kit.tasks.statement_read' => ['provider' => 'anthropic'],
        'ai.providers.anthropic.key' => null,
    ]);

    expect(aiGate()->blockedReason('team-1', 'statement_read'))->toBe('not_configured')
        ->and(aiGate()->blockedReason('team-1', 'whatsapp_message_read'))->toBeNull()
        ->and(aiGate()->terms('statement_read')->label)->toBe('Claude API (Anthropic)');
});

it('lets a provider that keeps data receive attachments unless told otherwise', function () {
    config(['ai-kit.routes.image.provider' => 'anthropic']);

    expect(aiGate()->blockedReason('team-1', 'receipt_read', Input::Image))->toBeNull()
        ->and(aiGate()->blockedReason('team-1', 'receipt_read', Input::Text))->toBeNull();
});

it('counts the budget of each task group apart', function () {
    config(['ai-kit.groups.whatsapp' => ['whatsapp_message_read', 'whatsapp_audio_transcribe']]);
    AiKit::fake(FakePolicy::make()->budget(2)->budget(1, 'whatsapp'));

    pastRun('team-1', 'whatsapp_message_read');
    pastRun('team-1', 'statement_read');
    pastRun('team-2', 'statement_read');
    pastRun('team-1', 'statement_read', ['created_at' => now()->subMonthNoOverflow()->startOfMonth()]);

    expect(aiGate()->blockedReason('team-1', 'whatsapp_audio_transcribe'))->toBe('budget')
        ->and(aiGate()->blockedReason('team-1', 'statement_read'))->toBeNull();

    pastRun('team-1', 'receipt_read');

    expect(aiGate()->blockedReason('team-1', 'statement_read'))->toBe('budget')
        ->and(aiGate()->blockedReason('team-2', 'statement_read'))->toBeNull();
});

it('takes the policy from the config', function () {
    config(['ai-kit.policy' => FakePolicy::class]);

    expect(app(AiPolicy::class))->toBeInstanceOf(FakePolicy::class);
});

it('gives the text of the reason', function () {
    app()->setLocale('pt_BR');

    expect((new AiUnavailable('budget'))->userMessage())->toBe('O limite de uso da inteligência artificial deste mês acabou.');
});
