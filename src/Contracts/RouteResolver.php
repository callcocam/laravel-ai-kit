<?php

namespace Callcocam\AiKit\Contracts;

use Callcocam\AiKit\Routing\Input;
use Callcocam\AiKit\Routing\Route;

/**
 * Escolhe provedor, modelo e teto de uma chamada pela tarefa e pelo tipo de
 * entrada. A padrão lê config/ai-kit.php; um projeto que guarda isso no banco
 * implementa a sua.
 */
interface RouteResolver
{
    public function resolve(?string $task, Input $input = Input::Text): Route;

    /**
     * Todas as rotas configuradas (para o ai:check testar cada uma).
     *
     * @return list<Route>
     */
    public function all(): array;
}
