<?php

use Votapil\VotaCrudGenerator\Services\StubRenderer;

/**
 * The Controller stub nests {{#if hasRelationships}} inside {{#if spatieQueryBuilder}}.
 * A single preg_replace_callback pass matches the outer block, returns its body verbatim
 * and never re-scans the substitution — so the inner tags survived into the generated file
 * and it did not parse:
 *
 *     Parse error: syntax error, unexpected token "{" ... {{#if hasRelationships}}
 *
 * Nothing catches that at generation time; the command reports success either way.
 */
it('resolves a conditional nested inside another', function () {
    $renderer = new StubRenderer();
    $render = fn (string $stub, array $vars) => (function () use ($stub, $vars) {
        $method = new ReflectionMethod(StubRenderer::class, 'processConditionals');

        return $method->invoke($this, $stub, $vars);
    })->call($renderer);

    $stub = <<<'STUB'
    {{#if outer}}
    kept-outer
    {{#if inner}}
    kept-inner
    {{/if inner}}
    {{/if outer}}
    STUB;

    expect($render($stub, ['outer' => true, 'inner' => true]))
        ->toContain('kept-outer')->toContain('kept-inner')->not->toContain('{{#if');

    expect($render($stub, ['outer' => true, 'inner' => false]))
        ->toContain('kept-outer')->not->toContain('kept-inner')->not->toContain('{{#if');

    // The whole block goes, inner tags and all.
    expect(trim($render($stub, ['outer' => false, 'inner' => true])))->toBe('');
});

it('leaves an unclosed block alone instead of spinning', function () {
    $renderer = new StubRenderer();
    $stub = "{{#if outer}}\nno closing tag\n";

    $out = (function () use ($stub) {
        return (new ReflectionMethod(StubRenderer::class, 'processConditionals'))
            ->invoke($this, $stub, ['outer' => true]);
    })->call($renderer);

    expect($out)->toBe($stub);
});
