<?php

namespace Callcocam\AiKit\Compare;

/**
 * Compara a resposta com o gabarito campo a campo. Os campos são as folhas do
 * gabarito em notação de ponto (`itens.0.valor`); as chaves que começam com `_`
 * são instruções do gabarito, não campos. Texto compara sem diferença de maiúscula nem
 * de espaço sobrando; número compara pelo valor ("1.234,56" = 1234.56),
 * com meio centavo de tolerância.
 */
final class FieldMatcher
{
    /**
     * @param  array<array-key, mixed>  $expected
     * @param  array<array-key, mixed>  $actual
     * @return array{total: int, hits: int, misses: list<string>}
     */
    public static function compare(array $expected, array $actual): array
    {
        $fields = self::leaves(array_filter($expected, fn ($key): bool => ! str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY));
        $answer = self::leaves($actual);
        $misses = [];

        foreach ($fields as $field => $value) {
            if (! array_key_exists($field, $answer) || ! self::same($value, $answer[$field])) {
                $misses[] = $field;
            }
        }

        return ['total' => count($fields), 'hits' => count($fields) - count($misses), 'misses' => $misses];
    }

    public static function same(mixed $expected, mixed $actual): bool
    {
        $left = self::number($expected);
        $right = self::number($actual);

        if ($left !== null && $right !== null) {
            return abs($left - $right) < 0.005;
        }

        if (is_bool($expected) || is_bool($actual) || $expected === null || $actual === null) {
            return $expected === $actual;
        }

        return self::text($expected) === self::text($actual);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private static function leaves(array $values, string $prefix = ''): array
    {
        $leaves = [];

        foreach ($values as $key => $value) {
            $path = $prefix.$key;

            if (is_array($value) && $value !== []) {
                $leaves += self::leaves($value, $path.'.');
            } else {
                $leaves[$path] = $value;
            }
        }

        return $leaves;
    }

    private static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $clean = preg_replace('/^R\$\s*/u', '', trim($value)) ?? '';

        // 1.234,56 ou 20,00 (Brasil) → 1234.56; 1234.56 fica como está.
        if (preg_match('/^-?\d{1,3}(\.\d{3})*,\d+$|^-?\d+,\d+$/', $clean) === 1) {
            $clean = str_replace(['.', ','], ['', '.'], $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    private static function text(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : (string) json_encode($value);

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    }
}
