<?php

use Callcocam\AiKit\Compare\FieldMatcher;

it('compares every leaf of the answer key', function () {
    $score = FieldMatcher::compare(
        ['_agent' => 'X', 'amount' => '1.234,56', 'payee' => 'Padaria Central', 'items' => [['name' => 'Pão', 'qty' => 2]], 'paid' => true],
        ['amount' => 1234.56, 'payee' => '  padaria   central ', 'items' => [['name' => 'pão', 'qty' => '3']], 'paid' => true, 'extra' => 'ignored'],
    );

    expect($score)->toBe(['total' => 5, 'hits' => 4, 'misses' => ['items.0.qty']]);
});

it('matches numbers by value', function (mixed $expected, mixed $actual, bool $same) {
    expect(FieldMatcher::same($expected, $actual))->toBe($same);
})->with([
    ['20,00', '20.00', true],
    ['R$ 42,90', 42.9, true],
    ['-234,56', '-234.56', true],
    ['1.500,00', 1500, true],
    ['10,00', '10,01', false],
    [true, 'true', false],
    [null, '', false],
]);

it('counts a missing field as a miss', function () {
    expect(FieldMatcher::compare(['amount' => '1,00'], []))->toBe(['total' => 1, 'hits' => 0, 'misses' => ['amount']]);
});
