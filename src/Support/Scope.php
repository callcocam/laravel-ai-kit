<?php

namespace Callcocam\AiKit\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * O valor gravado na coluna do dono (`ai-kit.runs.scope_column`) e na de quem
 * pediu: o projeto passa o id ou o próprio model.
 */
final class Scope
{
    public static function key(mixed $scope): string|int
    {
        $key = $scope instanceof Model ? $scope->getKey() : $scope;

        if (! is_string($key) && ! is_int($key)) {
            throw new InvalidArgumentException('The AI scope must be a model, a string or an int id.');
        }

        return $key;
    }

    public static function keyOrNull(mixed $value): string|int|null
    {
        return $value === null ? null : self::key($value);
    }
}
