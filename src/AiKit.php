<?php

namespace Callcocam\AiKit;

use Callcocam\AiKit\Contracts\AiPolicy;
use Callcocam\AiKit\Models\AiRun;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Assert;

/**
 * Ajudas para os testes do projeto. O provedor em si se finge com o fake do
 * laravel/ai (`MeuAgente::fake([...])`); isto liga a camada sem chave de verdade:
 *
 *   AiKit::fake();                                   // ligada, toda chave preenchida, tudo liberado
 *   AiKit::fake(FakePolicy::make()->budget(1));      // com uma política de teste
 *   AiKit::assertRan('statement_read', 'ok');
 */
final class AiKit
{
    public static function fake(?AiPolicy $policy = null): void
    {
        config(['ai-kit.enabled' => true]);

        foreach (array_keys((array) config('ai.providers', [])) as $provider) {
            if (blank(config("ai.providers.{$provider}.key"))) {
                config(["ai.providers.{$provider}.key" => 'fake-key']);
            }
        }

        if ($policy !== null) {
            app()->instance(AiPolicy::class, $policy);
        }
    }

    /**
     * @return Builder<AiRun>
     */
    public static function runs(): Builder
    {
        /** @var class-string<AiRun> $model */
        $model = config('ai-kit.runs.model', AiRun::class);

        return $model::query();
    }

    public static function assertRan(string $task, ?string $status = null, int $times = 1): void
    {
        $count = self::runs()->where('task', $task)->when($status !== null, fn (Builder $query) => $query->where('status', $status))->count();

        Assert::assertSame($times, $count, "Expected [{$task}] to be recorded {$times} time(s)".($status ? " as [{$status}]" : '')." in ai_runs, found {$count}.");
    }

    public static function assertNothingRan(): void
    {
        Assert::assertSame(0, self::runs()->count(), 'Expected no AI run to be recorded.');
    }
}
