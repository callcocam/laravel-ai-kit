<?php

use Callcocam\AiKit\Contracts\RouteResolver;
use Callcocam\AiKit\Routing\Input;
use Callcocam\AiKit\Routing\Route;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;

function aiRoute(?string $task, Input $input = Input::Text): Route
{
    return app(RouteResolver::class)->resolve($task, $input);
}

it('falls back to ai.default, the agent model and ai-kit.timeout without routes', function () {
    config(['ai-kit.timeout' => 90]);

    expect(aiRoute('statement_read', Input::Image))->toEqual(new Route('cloudflare', null, 90));
});

it('routes by input type', function () {
    config(['ai-kit.routes.image' => ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'timeout' => 60]]);

    expect(aiRoute('receipt_read', Input::Image))->toEqual(new Route('anthropic', 'claude-haiku-4-5', 60))
        ->and(aiRoute('receipt_read', Input::Text)->provider)->toBe('cloudflare');
});

it('lets a task override the route of its input', function () {
    config([
        'ai-kit.routes.text' => ['provider' => null, 'model' => '@cf/google/gemma-4-26b-a4b-it', 'timeout' => null],
        'ai-kit.tasks.whatsapp_message_read' => ['model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'timeout' => 20],
    ]);

    expect(aiRoute('whatsapp_message_read'))->toEqual(new Route('cloudflare', '@cf/meta/llama-3.3-70b-instruct-fp8-fast', 20))
        ->and(aiRoute('statement_read')->model)->toBe('@cf/google/gemma-4-26b-a4b-it');
});

it('does not carry the model of the route to another provider', function () {
    config([
        'ai-kit.routes.image' => ['provider' => 'cloudflare', 'model' => '@cf/google/gemma-4-26b-a4b-it'],
        'ai-kit.tasks.statement_read' => ['provider' => 'anthropic'],
    ]);

    expect(aiRoute('statement_read', Input::Image))->toEqual(new Route('anthropic', null, 120));
});

it('treats empty env values as not set', function () {
    config(['ai-kit.routes.text' => ['provider' => '', 'model' => '', 'timeout' => null]]);

    expect(aiRoute(null)->provider)->toBe('cloudflare')->and(aiRoute(null)->model)->toBeNull();
});

it('lists every distinct configured route', function () {
    config([
        'ai-kit.routes.image' => ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5'],
        'ai-kit.tasks.statement_read' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
    ]);

    expect(array_map(fn (Route $route) => $route->label(), app(RouteResolver::class)->all()))
        ->toBe(['cloudflare', 'anthropic:claude-haiku-4-5', 'anthropic:claude-sonnet-5']);
});

it('reads the input type from the attachments', function () {
    expect(Input::fromAttachments([]))->toBe(Input::Text)
        ->and(Input::fromAttachments([Image::fromBase64('eA==', 'image/jpeg')]))->toBe(Input::Image)
        ->and(Input::fromAttachments([Image::fromBase64('eA==', 'image/jpeg'), Document::fromString('x', 'application/pdf')]))->toBe(Input::Document)
        ->and(Input::fromAttachments([Audio::fromBase64('eA==', 'audio/ogg')]))->toBe(Input::Audio);
});

it('parses provider:model', function () {
    expect(Route::parse('anthropic:claude-haiku-4-5'))->toEqual(new Route('anthropic', 'claude-haiku-4-5'))
        ->and(Route::parse('cloudflare'))->toEqual(new Route('cloudflare'))
        ->and(Route::parse('cloudflare:@cf/meta/llama-3.3-70b-instruct-fp8-fast')->model)->toBe('@cf/meta/llama-3.3-70b-instruct-fp8-fast');
});
