<?php

use Votapil\VotaCrudGenerator\Commands\CrudGenerateCommand;

/**
 * --path used to be honoured by models, controllers, requests and resources, and
 * ignored by policies and factories. That is not a tidiness problem:
 *
 *  - Laravel's resolveFactoryName() strips App\Models\ and keeps the rest, so
 *    App\Models\Admin\Post wants Database\Factories\Admin\PostFactory. A factory
 *    written flat is never found, and Post::factory() throws at the first test
 *    that touches it.
 *  - Flat also means collision: App\Models\Post and App\Models\Admin\Post both
 *    claim Database\Factories\PostFactory and App\Policies\PostPolicy, and --force
 *    overwrites the first with the second without a word.
 */
it('puts a factory where Laravel will look for it', function (string $subPath, string $expected) {
    expect(CrudGenerateCommand::factoryRelativePath($subPath, 'Post'))->toBe($expected);
})->with([
    'root namespace'   => ['', 'factories/PostFactory.php'],
    'one level'        => ['\Admin', 'factories/Admin/PostFactory.php'],
    'nested'           => ['\Blog\V2', 'factories/Blog/V2/PostFactory.php'],
    // gatherMetadata builds the sub-path with a leading backslash; tolerate both
    // so a caller that trims it does not silently get factories/ in the wrong place.
    'no leading slash' => ['Admin', 'factories/Admin/PostFactory.php'],
]);

it('keeps the factory namespace a placeholder, not a hard-coded one', function () {
    $stub = file_get_contents(__DIR__.'/../stubs/Factory.stub');

    expect($stub)->toContain('namespace {{ factoryNamespace }};')
        ->and($stub)->not->toContain('namespace Database\\Factories;');
});

it('ships every stub with LF line endings', function () {
    // Stubs are copied verbatim into the host application. A stub stored with CRLF
    // hands every generated file CRLF on a project that uses LF, so git reports the
    // whole file as changed and the formatter fights it on the next run.
    foreach (glob(__DIR__.'/../stubs/*.stub') as $stub) {
        expect(file_get_contents($stub))->not->toContain("\r\n", basename($stub).' has CRLF line endings');
    }
});
