<?php

namespace Callcocam\AiKit\Exceptions;

use RuntimeException;

/**
 * A IA não pode ser usada agora. O motivo escolhe o texto
 * (`ai-kit::unavailable.<motivo>`, que o projeto sobrescreve em
 * lang/vendor/ai-kit). Motivos do pacote:
 *
 * - server_off: AI_ENABLED desligado;
 * - not_configured: falta a chave do provedor da rota;
 * - scope_off: o escopo (família, congregação…) desligou a IA (AiPolicy::enabled);
 * - trains_on_data: o provedor da rota treina com o conteúdo;
 * - budget: a cota do mês do grupo da tarefa acabou (AiPolicy::monthlyBudget);
 * - keeps_data: anexo para provedor que guarda o conteúdo, com
 *   `attachments_require_no_retention` ligado;
 * - no_vision / no_audio: o provedor da rota não lê imagem / não ouve áudio;
 * - provider_error: o provedor falhou (AiRunner);
 * - bad_answer: a resposta estruturada veio sem um campo exigido (AiRunner).
 *
 * A política do projeto pode recusar com um motivo próprio (RestrictsTasks).
 */
class AiUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("AI unavailable: {$reason}.");
    }

    public function userMessage(): string
    {
        return __('ai-kit::unavailable.'.$this->reason);
    }
}
