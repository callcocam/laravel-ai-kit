<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uma linha por chamada ao provedor de IA: de quem, quem pediu, para quê, com
 * qual modelo, quanto custou e como terminou. Nunca o texto do prompt nem do
 * documento (só um hash e os ids). Append-only (guard no model AiRun).
 *
 * A tabela e a coluna do dono vêm de config/ai-kit.php → runs. O dono e quem
 * pediu são texto (ulid, uuid ou número): troque por foreignUlid/foreignId com
 * constrained() na cópia publicada, se quiser a chave estrangeira.
 */
return new class extends Migration
{
    public function up(): void
    {
        $scope = (string) config('ai-kit.runs.scope_column', 'team_id');

        Schema::create((string) config('ai-kit.runs.table', 'ai_runs'), function (Blueprint $table) use ($scope) {
            $table->ulid('id')->primary();
            $table->string($scope, 64);
            $table->string('user_id', 64)->nullable();
            $table->string('task', 40);
            $table->string('provider', 40);
            $table->string('model', 120)->nullable();
            $table->string('prompt_hash', 64);
            $table->json('input_refs')->nullable();
            $table->unsignedInteger('attachments')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('status', 20);
            $table->string('error', 255)->nullable();
            $table->timestamp('created_at');

            $table->index([$scope, 'created_at']);
            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('ai-kit.runs.table', 'ai_runs'));
    }
};
