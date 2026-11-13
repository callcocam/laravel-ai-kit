<?php

use Callcocam\AiKit\AiKit;
use Callcocam\AiKit\Documents\DocumentPreparer;
use Callcocam\AiKit\Tests\Fixtures\ReceiptReader;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;

beforeEach(function () {
    $this->folder = sys_get_temp_dir().'/ai-compare-'.uniqid();
    File::ensureDirectoryExists($this->folder);
    File::copy(__DIR__.'/../fixtures/docs/comprovante-foto.jpg', $this->folder.'/padaria.jpg');
    File::put($this->folder.'/padaria.jpg.esperado.json', json_encode(['amount' => '42,90', 'payee' => 'Padaria Central']));
    File::put($this->folder.'/sem-gabarito.txt', 'nada');
});

afterEach(fn () => File::deleteDirectory($this->folder));

// Cloudflare acerta o valor e erra o nome; a Claude acerta os dois.
function fakeReaders(array &$prompts = []): void
{
    ReceiptReader::fake(function ($prompt, $attachments, $provider, $model) use (&$prompts) {
        $prompts[] = [$provider->name(), $model, $prompt, count($attachments)];
        $answer = $provider->name() === 'anthropic'
            ? ['amount' => '42.90', 'payee' => 'PADARIA CENTRAL']
            : ['amount' => 'R$ 42,90', 'payee' => 'Padaria'];

        return new StructuredTextResponse($answer, json_encode($answer), new TextUsage(1_000, 200), new Meta($provider->name(), $model));
    });
}

it('runs the same agent on each provider and scores against the answer key', function () {
    $prompts = [];
    fakeReaders($prompts);
    $csv = $this->folder.'/out.csv';

    $this->artisan('ai:compare', [
        'folder' => $this->folder,
        '--agent' => ReceiptReader::class,
        '--providers' => 'cloudflare,anthropic:claude-haiku-4-5',
        '--csv' => $csv,
        '--force' => true,
    ])
        ->expectsOutputToContain('sem-gabarito.txt: sem gabarito')
        ->expectsOutputToContain('1 documento(s) × 2 provedor(es) = 2 chamada(s)')
        ->expectsOutputToContain('50,0%')
        ->expectsOutputToContain('100,0%')
        ->expectsOutputToContain('US$ 0,00200')
        ->assertSuccessful();

    expect($prompts)->toBe([
        ['cloudflare', '@cf/google/gemma-4-26b-a4b-it', 'Leia o documento e devolva os campos pedidos.', 1],
        ['anthropic', 'claude-haiku-4-5', 'Leia o documento e devolva os campos pedidos.', 1],
    ]);

    $rows = array_map('str_getcsv', file($csv, FILE_IGNORE_NEW_LINES));
    expect($rows[0])->toBe(['file', 'route', 'hits', 'total', 'misses', 'input_tokens', 'output_tokens', 'cost', 'currency', 'ms', 'error'])
        ->and(array_slice($rows[1], 0, 9))->toBe(['padaria.jpg', 'cloudflare', '1', '2', 'payee', '1000', '200', '14.5456', 'neurons'])
        ->and(array_slice($rows[2], 0, 9))->toBe(['padaria.jpg', 'anthropic:claude-haiku-4-5', '2', '2', '', '1000', '200', '0.002', 'USD']);

    AiKit::assertNothingRan();
});

it('takes the agent and the prompt from the answer key, and sends the text of a digital PDF', function () {
    if (! DocumentPreparer::available()) {
        $this->markTestSkipped('poppler-utils não está instalado.');
    }

    File::delete($this->folder.'/padaria.jpg.esperado.json');
    File::copy(__DIR__.'/../fixtures/docs/extrato-texto.pdf', $this->folder.'/extrato.pdf');
    File::put($this->folder.'/extrato.esperado.json', json_encode(['_agent' => ReceiptReader::class, '_prompt' => 'Leia o extrato.', 'amount' => '-234,56']));
    $prompts = [];
    fakeReaders($prompts);

    $this->artisan('ai:compare', ['folder' => $this->folder, '--force' => true])->assertSuccessful();

    expect($prompts)->toHaveCount(1)
        ->and($prompts[0][2])->toStartWith("Leia o extrato.\n\nEXTRATO DE CONTA CORRENTE")
        ->and($prompts[0][3])->toBe(0)
        ->and(glob($this->folder.'/ai-compare-*.csv'))->toHaveCount(1);
});

it('counts the calls without making them on a dry run', function () {
    fakeReaders();

    $this->artisan('ai:compare', ['folder' => $this->folder, '--agent' => ReceiptReader::class, '--providers' => 'cloudflare,anthropic', '--dry-run' => true])
        ->expectsOutputToContain('= 2 chamada(s)')
        ->assertSuccessful();

    ReceiptReader::assertNeverPrompted();
});

it('records a failing provider and goes on', function () {
    ReceiptReader::fake(function ($prompt, $attachments, $provider) {
        if ($provider->name() === 'cloudflare') {
            throw new RuntimeException('down');
        }

        return ['amount' => '42,90', 'payee' => 'Padaria Central'];
    });

    $this->artisan('ai:compare', ['folder' => $this->folder, '--agent' => ReceiptReader::class, '--providers' => 'cloudflare,anthropic', '--force' => true])
        ->expectsOutputToContain('RuntimeException')
        ->assertSuccessful();
});

it('asks for the agent', function () {
    $this->artisan('ai:compare', ['folder' => $this->folder, '--force' => true])
        ->expectsOutputToContain('informe o agente')
        ->assertSuccessful();
});
