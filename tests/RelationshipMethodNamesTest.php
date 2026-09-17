<?php

use Votapil\VotaCrudGenerator\Services\DatabaseIntrospector;

/**
 * Reverse relationships are named after the other table, so a table pointing here through
 * several foreign keys claimed the same method name once per key. The generated model then
 * died on `Cannot redeclare`, and allowedIncludes() listed the same relation twice.
 *
 * Real case: transactions references currencies through currency_code, base_currency_code
 * and to_currency_code.
 */
it('keeps a single claim on a name plain', function () {
    $out = DatabaseIntrospector::disambiguateMethodNames([
        ['method' => 'receipts', 'foreign_key' => 'currency_code'],
        ['method' => 'accounts', 'foreign_key' => 'currency_code'],
    ]);

    expect(array_column($out, 'method'))->toBe(['receipts', 'accounts']);
});

it('gives every claimant its foreign key when a name is contested', function () {
    $out = DatabaseIntrospector::disambiguateMethodNames([
        ['method' => 'transactions', 'foreign_key' => 'currency_code'],
        ['method' => 'transactions', 'foreign_key' => 'base_currency_code'],
        ['method' => 'transactions', 'foreign_key' => 'to_currency_code'],
        ['method' => 'receipts', 'foreign_key' => 'currency_code'],
    ]);

    // All three are suffixed, not just the last two: the plain name would otherwise go to
    // whichever foreign key the schema read happened to return first.
    expect(array_column($out, 'method'))->toBe([
        'transactionsByCurrencyCode',
        'transactionsByBaseCurrencyCode',
        'transactionsByToCurrencyCode',
        'receipts',
    ]);
});

it('never emits the same method name twice', function () {
    $out = DatabaseIntrospector::disambiguateMethodNames([
        ['method' => 'transactions', 'foreign_key' => 'currency_code'],
        ['method' => 'transactions', 'foreign_key' => 'currency_code'],
    ]);

    $names = array_column($out, 'method');

    expect($names)->toBe(array_unique($names));
});
