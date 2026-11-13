<?php

namespace Callcocam\AiKit\Routing;

/**
 * Para onde vai uma chamada: o provedor (nome em config/ai.php → providers), o
 * modelo (null = o do agente ou o padrão do provedor) e o teto em segundos.
 */
final readonly class Route
{
    public function __construct(
        public string $provider,
        public ?string $model = null,
        public ?int $timeout = null,
    ) {}

    /**
     * "provedor" ou "provedor:modelo", como o --providers do ai:compare.
     */
    public static function parse(string $value): self
    {
        [$provider, $model] = array_pad(explode(':', trim($value), 2), 2, null);

        return new self($provider, $model === '' ? null : $model);
    }

    /**
     * O modelo que fica registrado quando a resposta não diz qual foi.
     */
    public function modelOrDefault(): ?string
    {
        $default = config('ai.providers.'.$this->provider.'.models.text.default');

        return $this->model ?? (is_string($default) ? $default : null);
    }

    public function label(): string
    {
        return $this->model === null ? $this->provider : $this->provider.':'.$this->model;
    }
}
