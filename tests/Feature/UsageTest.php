<?php

use Callcocam\AiKit\AiKit;
use Callcocam\AiKit\AiUsage;
use Callcocam\AiKit\Models\AiRun;
use Callcocam\AiKit\Testing\FakePolicy;
use Illuminate\Support\Carbon;

function usageRun(array $attributes): void
{
    AiRun::query()->create([
        'team_id' => 'team-1', 'task' => 'statement_read', 'provider' => 'cloudflare', 'model' => '@cf/google/gemma-4-26b-a4b-it',
        'prompt_hash' => str_repeat('a', 64), 'status' => 'ok', 'created_at' => now(), ...$attributes,
    ]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-13 15:00:00');
    config(['ai-kit.groups.whatsapp' => ['whatsapp_message_read']]);
});

it('counts the month of a scope by task group', function () {
    usageRun(['task' => 'whatsapp_message_read']);
    usageRun(['task' => 'statement_read', 'status' => 'failed']);
    usageRun(['task' => 'receipt_read']);
    usageRun(['team_id' => 'team-2']);
    usageRun(['created_at' => '2026-10-31 23:00:00']);

    $usage = app(AiUsage::class);

    expect($usage->runsThisMonth('team-1'))->toBe(3)
        ->and($usage->runsThisMonth('team-1', 'whatsapp'))->toBe(1)
        ->and($usage->runsThisMonth('team-1', 'default'))->toBe(2)
        ->and($usage->runsThisMonth('team-1', task: 'whatsapp_message_read'))->toBe(1)
        ->and($usage->runsThisMonth('team-1', task: 'anything_else'))->toBe(2);
});

it('estimates the cost of the month by the rates of each model', function () {
    usageRun(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'input_tokens' => 1_000_000, 'output_tokens' => 200_000]);
    usageRun(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'input_tokens' => 500_000, 'output_tokens' => 0]);
    usageRun(['task' => 'whatsapp_message_read', 'input_tokens' => 1000, 'output_tokens' => 100]);
    usageRun(['provider' => 'anthropic', 'model' => 'claude-without-price', 'input_tokens' => 10]);

    expect(app(AiUsage::class)->month('team-1'))->toBe([
        ['group' => 'whatsapp', 'provider' => 'cloudflare', 'model' => '@cf/google/gemma-4-26b-a4b-it', 'runs' => 1, 'input_tokens' => 1000, 'output_tokens' => 100, 'cost' => (1000 * 9091 + 100 * 27273) / 1_000_000, 'currency' => 'neurons'],
        ['group' => 'default', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'runs' => 2, 'input_tokens' => 1_500_000, 'output_tokens' => 200_000, 'cost' => 2.5, 'currency' => 'USD'],
        ['group' => 'default', 'provider' => 'anthropic', 'model' => 'claude-without-price', 'runs' => 1, 'input_tokens' => 10, 'output_tokens' => 0, 'cost' => null, 'currency' => 'USD'],
    ]);
});

it('measures each day against the daily allowance of the provider', function () {
    usageRun(['input_tokens' => 100_000, 'output_tokens' => 100_000, 'team_id' => 'team-2']);
    usageRun(['input_tokens' => 100_000, 'output_tokens' => 0, 'created_at' => '2026-11-12 10:00:00']);
    usageRun(['input_tokens' => 999_999, 'output_tokens' => 0, 'created_at' => '2026-10-01 10:00:00']);
    usageRun(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'input_tokens' => 1_000_000]);

    $daily = app(AiUsage::class)->daily(days: 3);

    expect($daily)->toBe([
        'provider' => 'cloudflare',
        'used' => 3636,
        'allowance' => 10000,
        'days' => [
            ['date' => '2026-11-11', 'used' => 0],
            ['date' => '2026-11-12', 'used' => 909],
            ['date' => '2026-11-13', 'used' => 3636],
        ],
    ])->and(app(AiUsage::class)->daily('anthropic'))->toBeNull();
});

it('sums up the card of a scope', function () {
    AiKit::fake(FakePolicy::make()->budget(30)->budget(100, 'whatsapp'));
    usageRun(['task' => 'whatsapp_message_read']);

    $summary = app(AiUsage::class)->summary('team-1', 'whatsapp_message_read');

    expect($summary['month'])->toBe(['runs' => 1, 'limit' => 100])
        ->and($summary['daily']['allowance'])->toBe(10000);
});
