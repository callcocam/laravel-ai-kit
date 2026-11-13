<?php

use Callcocam\AiKit\Models\AiRun;

it('shows the month of a scope and the daily allowance', function () {
    AiRun::query()->create([
        'team_id' => 'team-1', 'task' => 'statement_read', 'provider' => 'cloudflare', 'model' => '@cf/google/gemma-4-26b-a4b-it',
        'prompt_hash' => str_repeat('a', 64), 'status' => 'ok', 'input_tokens' => 110_000, 'output_tokens' => 0, 'created_at' => now(),
    ]);

    $this->artisan('ai:usage', ['scope' => 'team-1', '--days' => 2])
        ->expectsOutputToContain('Mês de team-1')
        ->expectsOutputToContain('1.000,0 neurons')
        ->expectsOutputToContain('Cota diária de cloudflare: 1000 de 10000')
        ->assertSuccessful();
});
