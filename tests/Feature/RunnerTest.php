<?php

use Callcocam\AiKit\AiKit;
use Callcocam\AiKit\AiRunner;
use Callcocam\AiKit\AiUsage;
use Callcocam\AiKit\Exceptions\AiUnavailable;
use Callcocam\AiKit\Models\AiRun;
use Callcocam\AiKit\Testing\FakePolicy;
use Callcocam\AiKit\Tests\Fixtures\PlainWriter;
use Callcocam\AiKit\Tests\Fixtures\ReceiptReader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;

function aiRunner(): AiRunner
{
    return app(AiRunner::class);
}

function fakeAnswer(array $structured, int $in = 900, int $out = 80): Closure
{
    return fn ($prompt, $attachments, $provider, $model) => new StructuredTextResponse($structured, json_encode($structured), new TextUsage($in, $out), new Meta($provider->name(), $model));
}

it('calls the agent and records the run without the content', function () {
    ReceiptReader::fake([fakeAnswer(['amount' => '42,90', 'payee' => 'Padaria'])]);

    $response = aiRunner()->run(new ReceiptReader, 'receipt_read', 'CPF 123.456.789-00, Padaria', 'team-1', 'user-1', inputRefs: ['import' => 'imp-1'], requiredKeys: ['amount', 'payee']);

    expect($response['amount'])->toBe('42,90');

    $run = AiRun::query()->sole();
    expect($run->only(['team_id', 'user_id', 'task', 'provider', 'model', 'prompt_hash', 'input_refs', 'attachments', 'input_tokens', 'output_tokens', 'status', 'error']))->toBe([
        'team_id' => 'team-1',
        'user_id' => 'user-1',
        'task' => 'receipt_read',
        'provider' => 'cloudflare',
        'model' => '@cf/google/gemma-4-26b-a4b-it',
        'prompt_hash' => hash('sha256', 'CPF 123.456.789-00, Padaria'),
        'input_refs' => ['import' => 'imp-1'],
        'attachments' => 0,
        'input_tokens' => 900,
        'output_tokens' => 80,
        'status' => 'ok',
        'error' => null,
    ]);

    expect(json_encode(AiRun::query()->toBase()->get()))->not->toContain('123.456.789');
});

it('sends to the provider and model of the route of the task', function () {
    config([
        'ai-kit.routes.image' => ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'timeout' => 45],
    ]);
    $seen = [];
    ReceiptReader::fake(function ($prompt, $attachments, $provider, $model) use (&$seen) {
        $seen[] = [$provider->name(), $model, count($attachments)];

        return ['amount' => '1,00', 'payee' => 'X'];
    });

    $runner = aiRunner();
    $runner->run(new ReceiptReader, 'receipt_read', 'Leia.', 'team-1', attachments: [Image::fromBase64('eA==', 'image/jpeg')]);
    $runner->run(new ReceiptReader, 'whatsapp_message_read', 'Gastei 20.', 'team-1');

    expect($seen)->toBe([['anthropic', 'claude-haiku-4-5', 1], ['cloudflare', '@cf/google/gemma-4-26b-a4b-it', 0]])
        ->and(AiRun::query()->orderBy('id')->pluck('provider')->all())->toBe(['anthropic', 'cloudflare'])
        ->and($runner->lastRun?->task)->toBe('whatsapp_message_read');
});

it('refuses and records rejected_schema when a required key is missing', function () {
    ReceiptReader::fake([fakeAnswer(['amount' => '42,90'])]);

    expect(fn () => aiRunner()->run(new ReceiptReader, 'receipt_read', 'Leia.', 'team-1', requiredKeys: ['amount', 'payee']))
        ->toThrow(fn (AiUnavailable $e) => expect($e->reason)->toBe('bad_answer'));

    expect(AiRun::query()->sole()->only(['status', 'error', 'output_tokens']))->toBe(['status' => 'rejected_schema', 'error' => 'missing: payee', 'output_tokens' => 80]);
});

it('does not check keys of an agent without structured output', function () {
    PlainWriter::fake(['Olá']);

    expect(aiRunner()->run(new PlainWriter, 'draft', 'Escreva.', 'team-1', requiredKeys: ['x'])->text)->toBe('Olá');
    AiKit::assertRan('draft', 'ok');
});

it('records a provider failure without its message', function () {
    ReceiptReader::fake(fn () => throw new RuntimeException('echo of the prompt: CPF 123'));

    expect(fn () => aiRunner()->run(new ReceiptReader, 'receipt_read', 'CPF 123', 'team-1'))
        ->toThrow(fn (AiUnavailable $e) => expect($e->reason)->toBe('provider_error'));

    expect(AiRun::query()->sole()->only(['status', 'error']))->toBe(['status' => 'failed', 'error' => 'RuntimeException']);
});

it('records nothing when the gate refuses', function () {
    AiKit::fake(FakePolicy::make()->off('team-1'));
    ReceiptReader::fake();

    expect(fn () => aiRunner()->run(new ReceiptReader, 'receipt_read', 'Leia.', 'team-1'))->toThrow(AiUnavailable::class);

    AiKit::assertNothingRan();
    ReceiptReader::assertNeverPrompted();
});

it('keeps the table and the owner column of the project', function () {
    Schema::drop('ai_runs');
    config(['ai-kit.runs' => ['table' => 'congregation_ai_runs', 'scope_column' => 'congregation_id', 'model' => AiRun::class]]);
    (require __DIR__.'/../../database/migrations/2026_11_13_000000_create_ai_runs_table.php')->up();

    $congregation = new class extends Model
    {
        protected $keyType = 'string';
    };
    $congregation->id = 'cong-1';
    $user = new class extends Model {};
    $user->id = 7;

    PlainWriter::fake(['ok']);
    aiRunner()->run(new PlainWriter, 'audit_review', 'Revise.', $congregation, $user);

    $row = (array) DB::table('congregation_ai_runs')->sole();
    expect($row['congregation_id'])->toBe('cong-1')->and($row['user_id'])->toBe('7')
        ->and(app(AiUsage::class)->runsThisMonth($congregation))->toBe(1);
});

it('writes through the model of the project', function () {
    config(['ai-kit.runs.model' => StampedRun::class]);
    PlainWriter::fake(['ok']);

    $run = tap(aiRunner())->run(new PlainWriter, 'draft', 'Escreva.', 'team-1')->lastRun;

    expect($run)->toBeInstanceOf(StampedRun::class)->and($run->error)->toBe('stamped');
});

it('is append-only', function () {
    PlainWriter::fake(['ok']);
    $run = tap(aiRunner())->run(new PlainWriter, 'draft', 'Escreva.', 'team-1')->lastRun;

    expect(fn () => $run->update(['status' => 'failed']))->toThrow(LogicException::class)
        ->and(fn () => $run->delete())->toThrow(LogicException::class);
});

class StampedRun extends AiRun
{
    protected static function booted(): void
    {
        parent::booted();
        static::creating(fn (AiRun $run) => $run->error = 'stamped');
    }
}
