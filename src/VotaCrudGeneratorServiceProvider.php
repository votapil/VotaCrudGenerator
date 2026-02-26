<?php

namespace Votapil\VotaCrudGenerator;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Votapil\VotaCrudGenerator\Commands\CrudGenerateCommand;
use Votapil\VotaCrudGenerator\Commands\CrudStubPublishCommand;

class VotaCrudGeneratorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('votacrudgenerator')
            ->hasConfigFile()
            ->hasCommands([
                CrudGenerateCommand::class,
                CrudStubPublishCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Services\DatabaseIntrospector::class);
        $this->app->singleton(Services\StubRenderer::class);
    }
}
