<?php

use Votapil\VotaCrudGenerator\Commands\CrudGenerateCommand;

/**
 * spatie/laravel-query-builder 7 made allowedFilters/allowedSorts/allowedIncludes variadic
 * instead of array-taking. A controller generated with the v6 shape answers 500 on every
 * list request — and the generator only runs at scaffold time, so nothing in the target
 * application fails until someone opens the endpoint. This package spans Laravel 11 to 13,
 * which covers both query-builder majors, so the installed version has to decide.
 */
it('reads the installed query-builder major', function (?string $version, bool $variadic) {
    expect(CrudGenerateCommand::queryBuilderIsVariadic($version))->toBe($variadic);
})->with([
    'v6 takes an array'        => ['6.4.3.0', false],
    'v5 takes an array'        => ['5.6.0.0', false],
    'v7 is variadic'           => ['7.3.5.0', true],
    'v8 is variadic'           => ['8.0.0.0', true],
    // Absent: nothing to stay compatible with, so generate for the current major.
    'not installed'            => [null, true],
    // A branch install tracks the newest code, not an old release.
    'dev-main'                 => ['dev-main', true],
    'empty string'             => ['', true],
]);
