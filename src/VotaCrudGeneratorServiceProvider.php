<?php

namespace Votapil\VotaCrudGenerator;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Votapil\VotaCrudGenerator\Commands\VotaCrudGeneratorCommand;

class VotaCrudGeneratorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('votacrudgenerator')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_votacrudgenerator_table')
            ->hasCommand(VotaCrudGeneratorCommand::class);
    }
}
