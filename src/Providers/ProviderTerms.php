<?php

namespace Callcocam\AiKit\Providers;

use Illuminate\Contracts\Support\Arrayable;

/**
 * O que um provedor faz com o conteúdo e quanto cobra, como declarado em
 * config/ai-kit.php → providers. Provedor desconhecido conta como um que treina
 * e guarda: nunca recebe nada.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ProviderTerms implements Arrayable
{
    /**
     * @param  array<string, array{input: float|int, output: float|int}>  $rates
     */
    public function __construct(
        public string $provider,
        public string $label,
        public bool $known,
        public bool $free,
        public bool $trainsOnInput,
        public bool $retainsInput,
        public ?int $retentionDays,
        public ?string $region,
        public bool $vision,
        public bool $audio,
        public ?string $checkedOn,
        public ?string $currency,
        public ?int $dailyAllowance,
        public array $rates,
    ) {}

    public static function for(string $provider): self
    {
        $terms = config('ai-kit.providers.'.$provider);
        $known = is_array($terms);
        $terms = $known ? $terms : [];

        return new self(
            provider: $provider,
            label: (string) ($terms['label'] ?? $provider),
            known: $known,
            free: (bool) ($terms['free'] ?? false),
            trainsOnInput: (bool) ($terms['trains_on_input'] ?? true),
            retainsInput: (bool) ($terms['retains_input'] ?? true),
            retentionDays: isset($terms['retention_days']) ? (int) $terms['retention_days'] : null,
            region: isset($terms['region']) ? (string) $terms['region'] : null,
            vision: (bool) ($terms['vision'] ?? false),
            audio: (bool) ($terms['audio'] ?? false),
            checkedOn: isset($terms['checked_on']) ? (string) $terms['checked_on'] : null,
            currency: isset($terms['currency']) ? (string) $terms['currency'] : null,
            dailyAllowance: isset($terms['daily_allowance']) ? (int) $terms['daily_allowance'] : null,
            rates: is_array($terms['rates'] ?? null) ? $terms['rates'] : [],
        );
    }

    /**
     * A chave do provedor está em config/ai.php (sem ela, `not_configured`).
     */
    public function configured(): bool
    {
        return filled(config('ai.providers.'.$this->provider.'.key'));
    }

    /**
     * Custo estimado de uma chamada na unidade de `currency`, ou null quando o
     * modelo não tem preço declarado.
     */
    public function cost(?string $model, int $inputTokens, int $outputTokens): ?float
    {
        $rate = $model === null ? null : $this->rateOf($model);

        if ($rate === null) {
            return null;
        }

        return ($inputTokens * $rate['input'] + $outputTokens * $rate['output']) / 1_000_000;
    }

    /**
     * A chave em `rates` que bate com o modelo devolvido pela chamada. Primeiro
     * o nome exato; sem isso, a chave configurada mais específica da qual o
     * modelo é uma versão datada — só quando o que vem depois do nome
     * configurado começa com um dígito (a Anthropic devolve "claude-haiku-4-5"
     * pedido como "claude-haiku-4-5-20251001" respondido, por exemplo; outros
     * provedores podem variar de forma parecida). Um sufixo com letra
     * ("-pro", "-mini") é um modelo diferente, não uma data, e não entra.
     *
     * @return array{input: float|int, output: float|int}|null
     */
    private function rateOf(string $model): ?array
    {
        if (isset($this->rates[$model])) {
            return $this->rates[$model];
        }

        $prefixes = array_filter(
            array_keys($this->rates),
            fn (string $key): bool => str_starts_with($model, $key.'-') && ctype_digit(mb_substr($model, mb_strlen($key) + 1, 1)),
        );

        if ($prefixes === []) {
            return null;
        }

        usort($prefixes, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $this->rates[$prefixes[0]];
    }

    /**
     * @return array{provider: string, label: string, free: bool, trains_on_input: bool, retains_input: bool, retention_days: int|null, region: string|null, vision: bool, audio: bool, checked_on: string|null, currency: string|null, daily_allowance: int|null}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'label' => $this->label,
            'free' => $this->free,
            'trains_on_input' => $this->trainsOnInput,
            'retains_input' => $this->retainsInput,
            'retention_days' => $this->retentionDays,
            'region' => $this->region,
            'vision' => $this->vision,
            'audio' => $this->audio,
            'checked_on' => $this->checkedOn,
            'currency' => $this->currency,
            'daily_allowance' => $this->dailyAllowance,
        ];
    }
}
