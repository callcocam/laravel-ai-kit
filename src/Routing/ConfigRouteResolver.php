<?php

namespace Callcocam\AiKit\Routing;

use Callcocam\AiKit\Contracts\RouteResolver;

/**
 * As rotas de config/ai-kit.php: a do tipo de entrada (`routes`), com a
 * sobrescrita da tarefa por cima (`tasks`). O que ficar sem valor cai no
 * `ai.default` do laravel/ai e no `ai-kit.timeout`.
 */
class ConfigRouteResolver implements RouteResolver
{
    public function resolve(?string $task, Input $input = Input::Text): Route
    {
        $route = $this->settings('ai-kit.routes.'.$input->value);
        $override = $task === null ? [] : $this->settings('ai-kit.tasks.'.$task);

        // Trocar o provedor sem dizer o modelo não herda o modelo da rota (é de outro provedor).
        if (isset($override['provider']) && ! isset($override['model'])) {
            unset($route['model']);
        }

        $route = [...$route, ...$override];

        return new Route(
            provider: $route['provider'] ?? (string) config('ai.default'),
            model: $route['model'] ?? null,
            timeout: isset($route['timeout']) ? (int) $route['timeout'] : (int) config('ai-kit.timeout'),
        );
    }

    public function all(): array
    {
        $routes = [$this->resolve(null)];

        foreach (Input::cases() as $input) {
            $routes[] = $this->resolve(null, $input);
        }

        foreach (array_keys((array) config('ai-kit.tasks', [])) as $task) {
            foreach (Input::cases() as $input) {
                $routes[] = $this->resolve((string) $task, $input);
            }
        }

        $unique = [];

        foreach ($routes as $route) {
            $unique[$route->label()] ??= $route;
        }

        return array_values($unique);
    }

    /**
     * Só as chaves com valor (o env vazio vira null na config).
     *
     * @return array{provider?: string, model?: string, timeout?: int|string}
     */
    private function settings(string $key): array
    {
        $settings = config($key);

        /** @var array{provider?: string, model?: string, timeout?: int|string} */
        return is_array($settings) ? array_filter($settings, fn ($value): bool => $value !== null && $value !== '') : [];
    }
}
