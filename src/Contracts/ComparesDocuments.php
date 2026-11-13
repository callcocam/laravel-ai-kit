<?php

namespace Callcocam\AiKit\Contracts;

/**
 * Opcional, no agente: o prompt que o app monta para ler um documento, para o
 * ai:compare mandar exatamente o mesmo. Sem isto, o comando usa o --prompt (ou o
 * `_prompt` do gabarito) seguido do texto do documento.
 */
interface ComparesDocuments
{
    /**
     * @param  string|null  $text  o texto do PDF (null quando o documento vai como imagens)
     */
    public function comparisonPrompt(?string $text, string $file): string;
}
