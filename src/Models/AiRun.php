<?php

namespace Callcocam\AiKit\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Uma chamada ao provedor de IA: de quem (a coluna `ai-kit.runs.scope_column`),
 * quem pediu, para quê, com qual modelo, quanto custou e como terminou. Nunca o
 * texto do prompt nem do documento (só um hash e os ids). Append-only: não se
 * edita nem se apaga.
 *
 * O projeto pode trocar por uma subclasse (`ai-kit.runs.model`) para colunas
 * extras, como um carimbo do autor preenchido no `creating`.
 *
 * @property string $id
 * @property string|null $user_id
 * @property string $task
 * @property string $provider
 * @property string|null $model
 * @property string $prompt_hash
 * @property array<string, mixed>|null $input_refs
 * @property int $attachments
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $duration_ms
 * @property string $status
 * @property string|null $error
 * @property Carbon $created_at
 */
class AiRun extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED_SCHEMA = 'rejected_schema';

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return (string) config('ai-kit.runs.table', 'ai_runs');
    }

    public static function scopeColumn(): string
    {
        return (string) config('ai-kit.runs.scope_column', 'team_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedBy(Builder $query, string|int $scope): Builder
    {
        return $query->where(static::scopeColumn(), $scope);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('AI runs are append-only.'));
        static::deleting(fn () => throw new LogicException('AI runs are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['input_refs' => 'array', 'created_at' => 'datetime'];
    }
}
